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
        return $this->advance($wallet, $order, $config, $market, $currentRoundEnd);
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $market
     */
    public function advance(PmCustodyWallet $wallet, PmSkipRoundOrder $order, array $config, array $market, int $currentRoundEnd): PmSkipRoundOrder
    {
        $order->refresh();

        if ((string) $order->token_id === '') {
            $order->status = PmSkipRoundOrder::STATUS_FAILED;
            $order->fail_reason = 'missing_token_id';
            $order->save();
            return $order;
        }

        return match ((string) $order->status) {
            PmSkipRoundOrder::STATUS_PREDICTED,
            PmSkipRoundOrder::STATUS_MARKET_RESOLVED => $this->placeLimitOrder($wallet, $order, $market),

            PmSkipRoundOrder::STATUS_LIMIT_SUBMITTED,
            PmSkipRoundOrder::STATUS_PARTIALLY_FILLED => $this->advanceSubmittedOrder($wallet, $order, $market, $currentRoundEnd),

            PmSkipRoundOrder::STATUS_CANCEL_REQUESTED => $this->advanceCanceledOrder($wallet, $order),

            PmSkipRoundOrder::STATUS_MARKET_BUY_SUBMITTED => $this->finalizeMarketBuy($order),

            default => $order,
        };
    }

    /**
     * @param array<string,mixed> $market
     */
    private function placeLimitOrder(PmCustodyWallet $wallet, PmSkipRoundOrder $order, array $market): PmSkipRoundOrder
    {
        $book = $this->trading->getOrderBook((string) $order->token_id);
        $level = $this->orderbookService->pickHighestAskLevelForBuy($book);

        if ($level === null) {
            $order->status = PmSkipRoundOrder::STATUS_FAILED;
            $order->fail_reason = 'missing_ask_level';
            $order->snapshot = array_merge($order->snapshot ?? [], ['orderbook' => $book]);
            $order->save();
            return $order;
        }

        $limitPrice = (string) $level['price'];
        if (bccomp($limitPrice, '0.52', 8) === 1) {
            $limitPrice = '0.52';
        }
        $limitPrice = bcadd($limitPrice, '0', 2);
        $limitOrderSize = bcadd((string) $order->bet_amount, '0', 2);
        $limitOrderNotional = bcmul($limitOrderSize, $limitPrice, 8);

        $allowanceStatus = $this->trading->getAllowanceStatus($wallet);
        if (($allowanceStatus['is_approved'] ?? false) !== true) {
            $approval = $this->trading->approveCollateral($wallet);
            $order->snapshot = array_merge($order->snapshot ?? [], [
                'buy_allowance' => $allowanceStatus,
                'auto_approve_collateral' => $approval,
                'calculated_notional' => $limitOrderNotional,
            ]);
            $order->save();
            return $order->fresh();
        }

        $readiness = $this->trading->getTradingReadiness(
            $wallet,
            PolymarketTradingService::SIDE_BUY,
            (string) $order->token_id,
            $limitPrice,
            $limitOrderSize,
        );
        if (($readiness['is_ready'] ?? false) !== true) {
            $failureCode = (string) ($readiness['failure_code'] ?? 'trade_not_ready');
            $order->status = PmSkipRoundOrder::STATUS_FAILED;
            $order->fail_reason = $failureCode;
            $order->snapshot = array_merge($order->snapshot ?? [], [
                'buy_allowance' => $allowanceStatus,
                'buy_readiness' => $readiness,
            ]);
            $order->save();
            return $order;
        }

        $order->place_started_at = $order->place_started_at ?: now();
        $order->limit_price = $limitPrice;
        $order->limit_order_size = $limitOrderSize;
        $order->limit_order_notional = $limitOrderNotional;
        $order->remaining_notional = $limitOrderNotional;
        $order->snapshot = array_merge($order->snapshot ?? [], ['initial_orderbook' => $book, 'selected_level' => $level, 'buy_readiness' => $readiness]);
        $order->save();

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

        $response = is_array($placed['response'] ?? null) ? $placed['response'] : [];
        $order->remote_order_id = $this->extractRemoteOrderId($response);
        $order->remote_client_order_id = (string) ($response['clientOrderId'] ?? $response['client_order_id'] ?? '');
        $order->limit_placed_at = now();
        $order->status = PmSkipRoundOrder::STATUS_LIMIT_SUBMITTED;
        $order->snapshot = array_merge($order->snapshot ?? [], ['limit_order_request' => $placed['request'] ?? [], 'limit_order_response' => $response]);
        $order->save();

        return $order->fresh();
    }

    /**
     * @param array<string,mixed> $market
     */
    private function advanceSubmittedOrder(PmCustodyWallet $wallet, PmSkipRoundOrder $order, array $market, int $currentRoundEnd): PmSkipRoundOrder
    {
        // 续跑到已提交挂单的订单时，进入持续轮询：
        // - 只要还没全成，就每秒刷新一次远端订单状态
        // - 一直等到接近本轮结束、满足撤单条件时才发起撤单并退出这段轮询
        // - 如果中途已经全部成交，则直接标记 filled 返回
        while (true) {
            $this->refreshRemoteOrder($wallet, $order);
            $order->refresh();

            // 如果剩余未成交金额已经 <= 0，说明这笔挂单已全部成交。
            if (bccomp((string) $order->remaining_notional, '0', 8) <= 0) {
                $order->status = PmSkipRoundOrder::STATUS_FILLED;
                $order->save();
                return $order;
            }

            // 距离当前轮结束只剩 5 秒时，仍未全部成交，则发起撤单并退出循环。
            if ($order->remote_order_id && $order->cancel_requested_at === null && time() >= ($currentRoundEnd - 5)) {
                $order->cancel_requested_at = now();
                $order->status = PmSkipRoundOrder::STATUS_CANCEL_REQUESTED;
                $order->save();
                $cancelResponse = $this->cancelOrderService->cancel($wallet, (string) $order->remote_order_id);
                $order->snapshot = array_merge($order->snapshot ?? [], ['cancel_response' => $cancelResponse]);
                $order->save();
                break;
            }
            if(time() >= ($currentRoundEnd - 20)){
                break;
            }

            sleep(1);
        }

        // 撤单后继续推进：确认撤单、检查剩余金额、必要时补市价单。
        return $this->advanceCanceledOrder($wallet, $order->fresh());
    }

    private function advanceCanceledOrder(PmCustodyWallet $wallet, PmSkipRoundOrder $order): PmSkipRoundOrder
    {
        if ($order->remote_order_id) {
            // 撤单请求发出后，再拉一次远端订单，确认是否还有新增成交，
            // 并把 cancel_confirmed_at 补上，表示撤单流程已经推进过。
            $this->refreshRemoteOrder($wallet, $order);
            $order->refresh();
            $order->cancel_confirmed_at = $order->cancel_confirmed_at ?: now();
            $order->save();
        }

        // 如果撤单前其实已经全部成交，就不需要再补市价单了。
        if (bccomp((string) $order->remaining_notional, '0', 8) <= 0) {
            $order->status = PmSkipRoundOrder::STATUS_FILLED;
            $order->save();
            return $order;
        }

        // 还有剩余未成交金额时，按当前盘口估一个可立即成交的价格，
        // 然后补一笔 FOK BUY 单，把剩余金额尽快买进去。
        $marketPrice = $this->trading->getOrderBookMarketPrice((string) $order->token_id, PolymarketTradingService::SIDE_BUY, (string) $order->remaining_notional);
        $price = (string) ($marketPrice['price'] ?? $order->limit_price ?? '0');
        if ($price === '' || $price === '0') {
            $order->status = PmSkipRoundOrder::STATUS_FAILED;
            $order->fail_reason = 'missing_market_buy_price';
            $order->snapshot = array_merge($order->snapshot ?? [], ['market_price' => $marketPrice]);
            $order->save();
            return $order;
        }

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
        $remoteOrderId = $this->extractRemoteOrderId($response);
        $executedNotional = (string) ($order->remaining_notional ?? '0');

        $order->market_buy_at = now();
        $order->market_buy_notional = $executedNotional;
        $order->status = PmSkipRoundOrder::STATUS_MARKET_BUY_SUBMITTED;
        $order->remaining_notional = '0';
        $order->snapshot = array_merge($order->snapshot ?? [], [
            'market_buy_request' => $placed['request'] ?? [],
            'market_buy_response' => $response,
            'market_buy_remote_order_id' => $remoteOrderId,
            'market_price' => $marketPrice,
        ]);
        $order->save();

        return $order->fresh();
    }

    private function finalizeMarketBuy(PmSkipRoundOrder $order): PmSkipRoundOrder
    {
        $order->remaining_notional = '0';
        $order->status = PmSkipRoundOrder::STATUS_FILLED;
        $order->save();

        return $order;
    }

    private function refreshRemoteOrder(PmCustodyWallet $wallet, PmSkipRoundOrder $order): void
    {
        if (!$order->remote_order_id) {
            return;
        }

        try {
            // 读取远端单个订单的最新状态，这是判断“挂单有没有成交”的核心来源。
            $remote = $this->trading->getUserOrder($wallet, (string) $order->remote_order_id);
            // var_dump($remote);
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'json response is not an array') || str_contains($message, 'response body: null')) {
                // Polymarket 单笔订单接口偶尔会返回 null。
                // 这时不直接把整条续跑链路打崩，只把异常记到 snapshot，等待下一轮重试。
                $order->snapshot = array_merge($order->snapshot ?? [], [
                    'last_remote_order_error' => [
                        'message' => $e->getMessage(),
                        'class' => $e::class,
                        'at' => now()->toDateTimeString(),
                    ],
                ]);
                $order->save();
                return;
            }

            throw $e;
        }

        // 兼容不同字段名，统一提取：
        // - matchedSize：已成交 size
        // - matchedNotional：已成交金额
        $matchedSize = (string) ($remote['size_matched'] ?? $remote['filled_size'] ?? $remote['matched_size'] ?? $remote['sizeMatched'] ?? '0');
        $matchedNotional = (string) ($remote['takingAmount'] ?? $remote['filledAmount'] ?? $remote['filled_amount'] ?? '0');
        if (($matchedNotional === '' || $matchedNotional === '0') && preg_match('/^\d+(\.\d+)?$/', (string) $order->limit_price) === 1 && preg_match('/^\d+(\.\d+)?$/', $matchedSize) === 1) {
            // 有些返回没有直接给成交金额，这时用“成交 size × 挂单 price”回推名义成交金额。
            $matchedNotional = bcmul((string) $order->limit_price, $matchedSize, 8);
        }

        $order->matched_size = $matchedSize;
        $order->matched_notional = $matchedNotional;

        // remaining_notional 表示还剩多少金额没成交。
        // 通过“理论总成交金额 - 已成交金额”来判断是否已全部成交。
        $remaining = bcsub((string) $order->limit_order_notional, $matchedNotional, 8);
        $order->remaining_notional = bccomp($remaining, '0', 8) === -1 ? '0' : $remaining;

        $avgFillPrice = (string) ($remote['avg_price'] ?? $remote['avgPrice'] ?? '');
        $order->avg_fill_price = $avgFillPrice === '' ? null : $avgFillPrice;
        $order->snapshot = array_merge($order->snapshot ?? [], ['last_remote_order' => $remote]);

        // 只要有成交金额，就根据 remaining_notional 判断：
        // - > 0：部分成交
        // - <= 0：全部成交
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
