<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyWallet;
use App\Models\Pm\PmSkipRoundOrder;

class SkipRoundExecutionService
{
    public function __construct(
        private readonly PolymarketTradingService $trading,
        private readonly SkipRoundOrderbookService $orderbookService,
        private readonly SkipRoundCancelOrderService $cancelOrderService,
    ) {
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $market
     */
    public function execute(PmCustodyWallet $wallet, PmSkipRoundOrder $order, array $config, array $market, int $currentRoundEnd): PmSkipRoundOrder
    {
        // 第一步先拉当前 token 的 orderbook。
        // 隔一轮策略不是直接市价扫单，而是先尝试在卖盘里挑一个“最有利的挂单价位”去挂 BUY 限价单。
        $book = $this->trading->getOrderBook((string) $order->token_id);
        $level = $this->orderbookService->pickHighestAskLevelForBuy($book);

        if ($level === null) {
            // 连可用卖盘都没有，说明当前市场深度不足，无法执行策略，直接把订单记为失败。
            $order->status = PmSkipRoundOrder::STATUS_FAILED;
            $order->fail_reason = 'missing_ask_level';
            $order->snapshot = array_merge($order->snapshot ?? [], ['orderbook' => $book]);
            $order->save();
            return $order;
        }

        // 选中的卖盘价作为首笔限价单价格。
        $limitPrice = (string) $level['price'];
        if (bccomp($limitPrice, '0.52', 8) === 1) {
            // 对价格做一个上限保护，避免在极端盘口下直接用过高价格买入。
            $limitPrice = '0.52';
        }
        $limitPrice = bcadd($limitPrice,0,2);
        // bet_amount 是按金额下单，这里换算成本次限价单要挂的份额数量。
        // BUY 单不能直接保留过长小数位，否则 makerAmount / takerAmount 在向下取整后，
        // 会把隐含价格还原成 0.4899999... 这类非法 tick price，被 CLOB 拒单。
        // 因此和现有预检逻辑保持一致，BUY size 按 2 位小数截断。
        $limitOrderSize = bcdiv((string) $order->bet_amount, $limitPrice, 2);

        // 先把即将发出的限价单参数、起始盘口快照记到订单里，方便后续排障和复盘。
        $order->place_started_at = now();
        $order->limit_price = $limitPrice;
        $order->limit_order_size = $limitOrderSize;
        $order->limit_order_notional = (string) $order->bet_amount;
        $order->remaining_notional = (string) $order->bet_amount;
        $order->snapshot = array_merge($order->snapshot ?? [], ['initial_orderbook' => $book, 'selected_level' => $level]);
        $order->save();

        // 第二步发真实限价 BUY 单：
        // - price 用挑出来的盘口价格
        // - size 用金额换算后的份额
        // - GTC 挂单，先让它吃自然成交
        // - defer_exec=true，按挂单模式处理
        $placed = $this->trading->placeOrder($wallet, [
            'token_id' => (string) $order->token_id,
            'market_id' => (string) ($market['market_id'] ?? $order->market_id ?? ''),
            'outcome' => (string) ($order->predicted_side ?? ''),
            'side' => PolymarketTradingService::SIDE_BUY,
            'price' => $limitPrice,
            'size' => $limitOrderSize,
            'order_type' => 'GTC',
            'defer_exec' => true,
            'expiration' => '0',
        ]);

        // 保存远端订单号、客户端订单号，以及请求/响应原文。
        $response = is_array($placed['response'] ?? null) ? $placed['response'] : [];
        $order->remote_order_id = $this->extractRemoteOrderId($response);
        $order->remote_client_order_id = (string) ($response['clientOrderId'] ?? $response['client_order_id'] ?? '');
        $order->limit_placed_at = now();
        $order->status = PmSkipRoundOrder::STATUS_LIMIT_SUBMITTED;
        $order->snapshot = array_merge($order->snapshot ?? [], ['limit_order_request' => $placed['request'] ?? [], 'limit_order_response' => $response]);
        $order->save();

        // 第三步轮询到本轮结束前 5 秒：
        // - 能成交多少先成交多少
        // - 若提前全部成交则直接结束
        // - 若到 deadline 仍没成交完，后续会走撤单 + 市价补单
        $this->pollUntilDeadline($wallet, $order, $currentRoundEnd);

        return $order->fresh();
    }

    private function pollUntilDeadline(PmCustodyWallet $wallet, PmSkipRoundOrder $order, int $currentRoundEnd): void
    {
        //测试期间直接撤单
        if (env('APP_ENV') === 'local') {
            $this->cancelOrderService->cancel($wallet, (string) $order->remote_order_id);
            $order->status = PmSkipRoundOrder::STATUS_CANCEL_REQUESTED;
            $order->save();
            return;
        }
        while (time() < ($currentRoundEnd - 5)) {
            $this->refreshRemoteOrder($wallet, $order);
            if (bccomp((string) $order->remaining_notional, '0', 8) <= 0) {
                $order->status = PmSkipRoundOrder::STATUS_FILLED;
                $order->save();
                return;
            }
            sleep(1);
            $order->refresh();
        }

        if ($order->remote_order_id) {
            $order->cancel_requested_at = now();
            $order->status = PmSkipRoundOrder::STATUS_CANCEL_REQUESTED;
            $order->save();
            $cancelResponse = $this->cancelOrderService->cancel($wallet, (string) $order->remote_order_id);
            $order->cancel_confirmed_at = now();
            $order->snapshot = array_merge($order->snapshot ?? [], ['cancel_response' => $cancelResponse]);
            $order->save();
            $this->refreshRemoteOrder($wallet, $order);
        }

        if (bccomp((string) $order->remaining_notional, '0', 8) === 1) {
            $marketPrice = $this->trading->getOrderBookMarketPrice((string) $order->token_id, PolymarketTradingService::SIDE_BUY, (string) $order->remaining_notional);
            $price = (string) ($marketPrice['price'] ?? $order->limit_price ?? '0');
            if ($price !== '0' && $price !== '') {
                $size = bcdiv((string) $order->remaining_notional, $price, 2);
                $placed = $this->trading->placeOrder($wallet, [
                    'token_id' => (string) $order->token_id,
                    'market_id' => (string) ($order->market_id ?? ''),
                    'outcome' => (string) ($order->predicted_side ?? ''),
                    'side' => PolymarketTradingService::SIDE_BUY,
                    'price' => $price,
                    'size' => $size,
                    'order_type' => 'FOK',
                    'defer_exec' => false,
                    'expiration' => '0',
                ]);

                $response = is_array($placed['response'] ?? null) ? $placed['response'] : [];
                $order->market_buy_at = now();
                $order->market_buy_notional = (string) $order->remaining_notional;
                $order->status = PmSkipRoundOrder::STATUS_MARKET_BUY_SUBMITTED;
                $order->snapshot = array_merge($order->snapshot ?? [], [
                    'market_buy_request' => $placed['request'] ?? [],
                    'market_buy_response' => $response,
                    'market_buy_remote_order_id' => $this->extractRemoteOrderId($response),
                    'market_price' => $marketPrice,
                ]);
                $order->remaining_notional = '0';
                $order->save();
            }
        }
    }

    private function refreshRemoteOrder(PmCustodyWallet $wallet, PmSkipRoundOrder $order): void
    {
        if (!$order->remote_order_id) {
            return;
        }

        $remote = $this->trading->getUserOrder($wallet, (string) $order->remote_order_id);
        $matchedSize = (string) ($remote['size_matched'] ?? $remote['filled_size'] ?? $remote['matched_size'] ?? $remote['sizeMatched'] ?? '0');
        $matchedNotional = (string) ($remote['takingAmount'] ?? $remote['filledAmount'] ?? $remote['filled_amount'] ?? '0');
        if (($matchedNotional === '' || $matchedNotional === '0') && preg_match('/^\d+(\.\d+)?$/', (string) $order->limit_price) === 1 && preg_match('/^\d+(\.\d+)?$/', $matchedSize) === 1) {
            $matchedNotional = bcmul((string) $order->limit_price, $matchedSize, 8);
        }

        $order->matched_size = $matchedSize;
        $order->matched_notional = $matchedNotional;
        $remaining = bcsub((string) $order->bet_amount, $matchedNotional, 8);
        $order->remaining_notional = bccomp($remaining, '0', 8) === -1 ? '0' : $remaining;
        $order->avg_fill_price = (string) ($remote['avg_price'] ?? $remote['avgPrice'] ?? $order->avg_fill_price);
        $order->snapshot = array_merge($order->snapshot ?? [], ['last_remote_order' => $remote]);
        if (bccomp((string) $order->matched_notional, '0', 8) === 1) {
            $order->status = bccomp((string) $order->remaining_notional, '0', 8) === 1
                ? PmSkipRoundOrder::STATUS_PARTIALLY_FILLED
                : PmSkipRoundOrder::STATUS_FILLED;
        }
        $order->save();
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractRemoteOrderId(array $response): string
    {
        foreach (['id', 'orderID', 'orderId'] as $key) {
            $value = trim((string) ($response[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
