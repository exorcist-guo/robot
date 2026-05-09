<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyWallet;
use App\Models\Pm\PmSkipRoundOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

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

            // 距离当前轮结束只剩 10 秒时，仍未全部成交，则发起撤单并退出循环。
            if ($order->remote_order_id && $order->cancel_requested_at === null && time() >= ($currentRoundEnd - 10)) {
                $order->cancel_requested_at = now();
                $order->status = PmSkipRoundOrder::STATUS_CANCEL_REQUESTED;
                $order->save();
                $cancelResponse = $this->cancelOrderService->cancel($wallet, (string) $order->remote_order_id);
                $order->snapshot = array_merge($order->snapshot ?? [], ['cancel_response' => $cancelResponse]);
                $order->save();

                $notCanceled = is_array($cancelResponse['not_canceled'] ?? null) ? $cancelResponse['not_canceled'] : [];
                $notCanceledMessage = (string) ($notCanceled[(string) $order->remote_order_id] ?? '');
                if ($notCanceledMessage !== '' && str_contains(strtolower($notCanceledMessage), 'already canceled or matched')) {
                    // 远端已经找不到这笔单，通常表示它已被撤掉或已成交，
                    // 这时不要立刻补一笔市价单，否则可能造成重复持仓。
                    $order->snapshot = array_merge($order->snapshot ?? [], [
                        'cancel_resolution' => 'remote_order_missing_after_cancel',
                        'cancel_resolution_message' => $notCanceledMessage,
                    ]);
                    $order->save();
                    return $order->fresh();
                }
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
            // 撤单请求发出后，不只查一次远端状态，而是循环确认 3 次，
            // 每次间隔 2 秒，尽量避免刚撤单时远端状态还未来得及同步。
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $this->refreshRemoteOrder($wallet, $order);
                $order->refresh();

                $lastRemoteOrder = is_array($order->snapshot['last_remote_order'] ?? null) ? $order->snapshot['last_remote_order'] : [];
                $remoteStatus = strtoupper(trim((string) ($lastRemoteOrder['status'] ?? '')));
                $cancelResponse = is_array($order->snapshot['cancel_response'] ?? null) ? $order->snapshot['cancel_response'] : [];
                $canceledOrders = is_array($cancelResponse['canceled'] ?? null) ? $cancelResponse['canceled'] : [];
                $isCanceledByResponse = in_array((string) $order->remote_order_id, array_map('strval', $canceledOrders), true);

                if ($remoteStatus === 'CANCELED' || $isCanceledByResponse) {
                    $order->cancel_confirmed_at = $order->cancel_confirmed_at ?: now();
                    $order->save();
                    break;
                }

                if ($attempt < 2) {
                    sleep(2);
                }
            }
        }

        // 如果撤单前其实已经全部成交，就不需要再补市价单了。
        if (bccomp((string) $order->remaining_notional, '0', 8) <= 0) {
            $order->status = PmSkipRoundOrder::STATUS_FILLED;
            $order->save();
            return $order;
        }

        // 只有在远端状态已经明确是 CANCELED 时，才允许补市价单。
        // 否则宁可停在当前状态，也不能在“原单还可能活着”的情况下继续补单，避免重复持仓。
        $lastRemoteOrder = is_array($order->snapshot['last_remote_order'] ?? null) ? $order->snapshot['last_remote_order'] : [];
        $remoteStatus = strtoupper(trim((string) ($lastRemoteOrder['status'] ?? '')));
        $cancelResponse = is_array($order->snapshot['cancel_response'] ?? null) ? $order->snapshot['cancel_response'] : [];
        $canceledOrders = is_array($cancelResponse['canceled'] ?? null) ? $cancelResponse['canceled'] : [];
        $isCanceledByResponse = in_array((string) $order->remote_order_id, array_map('strval', $canceledOrders), true);
        if ($remoteStatus !== 'CANCELED' && !$isCanceledByResponse) {
            $order->snapshot = array_merge($order->snapshot ?? [], [
                'cancel_resolution' => 'cancel_not_confirmed',
                'cancel_resolution_message' => 'remote order not confirmed canceled; skip market buy to avoid duplicate position',
            ]);
            $order->save();
            return $order;
        }

        // 还有剩余未成交份额时，按当前盘口估一个可立即成交的价格，
        // 然后补一笔 FOK BUY 单，把剩余 size 尽快买进去。
        $filledSize = preg_match('/^\d+(\.\d+)?$/', (string) $order->matched_size) === 1 ? (string) $order->matched_size : '0';
        $remainingSize = bcsub((string) $order->limit_order_size, $filledSize, 8);
        if (bccomp($remainingSize, '0', 8) <= 0) {
            $order->status = PmSkipRoundOrder::STATUS_FILLED;
            $order->save();
            return $order;
        }

        $marketPrice = $this->trading->getOrderBookMarketPrice(
            (string) $order->token_id,
            PolymarketTradingService::SIDE_BUY,
            '0',
            $remainingSize
        );
        $executionPrice = (string) ($marketPrice['price'] ?? $order->limit_price ?? '0');
        if ($executionPrice === '' || $executionPrice === '0') {
            $order->status = PmSkipRoundOrder::STATUS_FAILED;
            $order->fail_reason = 'missing_market_buy_price';
            $order->snapshot = array_merge($order->snapshot ?? [], [
                'market_price' => $marketPrice,
                'remaining_size' => $remainingSize,
            ]);
            $order->save();
            return $order;
        }

        $normalizedPrice = BigDecimal::of($executionPrice)->toScale(4, RoundingMode::DOWN)->stripTrailingZeros()->__toString();
        $normalizedSize = BigDecimal::of($remainingSize)
            ->toScale(2, RoundingMode::DOWN)
            ->stripTrailingZeros()
            ->__toString();
        $normalizedNotional = BigDecimal::of($normalizedPrice)
            ->multipliedBy($normalizedSize)
            ->toScale(6, RoundingMode::DOWN)
            ->__toString();

        try {
            $placed = $this->trading->placeOrder($wallet, [
                'token_id' => (string) $order->token_id,
                'market_id' => (string) ($order->market_id ?? ''),
                'outcome' => (string) ($order->predicted_side ?? ''),
                'side' => PolymarketTradingService::SIDE_BUY,
                'price' => $normalizedPrice,
                'size' => $normalizedSize,
                'order_type' => 'FOK',
                'defer_exec' => false,
                'expiration' => '0',
            ]);
        } catch (\Throwable $e) {
            $order->snapshot = array_merge($order->snapshot ?? [], [
                'market_price' => $marketPrice,
                'remaining_size' => $remainingSize,
                'market_buy_normalized_price' => $normalizedPrice,
                'market_buy_normalized_size' => $normalizedSize,
                'market_buy_normalized_notional' => $normalizedNotional,
                'market_buy_submit_error' => [
                    'message' => $e->getMessage(),
                    'class' => $e::class,
                ],
            ]);
            throw $e;
        }

        $response = is_array($placed['response'] ?? null) ? $placed['response'] : [];
        $remoteOrderId = $this->extractRemoteOrderId($response);
        $executedNotional = $normalizedNotional;

        $order->market_buy_at = now();
        $order->market_buy_notional = $executedNotional;
        $order->status = PmSkipRoundOrder::STATUS_MARKET_BUY_SUBMITTED;
        $order->remaining_notional = '0';
        $order->snapshot = array_merge($order->snapshot ?? [], [
            'market_buy_request' => $placed['request'] ?? [],
            'market_buy_response' => $response,
            'market_buy_remote_order_id' => $remoteOrderId,
            'market_price' => $marketPrice,
            'market_buy_normalized_price' => $normalizedPrice,
            'market_buy_normalized_size' => $normalizedSize,
            'market_buy_normalized_notional' => $executedNotional,
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
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'json response is not an array') || str_contains($message, 'response body: null')) {
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

        $matchedSize = (string) ($remote['size_matched'] ?? $remote['filled_size'] ?? $remote['matched_size'] ?? $remote['sizeMatched'] ?? '0');
        $matchedNotional = (string) ($remote['takingAmount'] ?? $remote['filledAmount'] ?? $remote['filled_amount'] ?? '0');
        if (($matchedNotional === '' || $matchedNotional === '0') && preg_match('/^\d+(\.\d+)?$/', (string) $order->limit_price) === 1 && preg_match('/^\d+(\.\d+)?$/', $matchedSize) === 1) {
            $matchedNotional = bcmul((string) $order->limit_price, $matchedSize, 8);
        }

        $order->matched_size = $matchedSize;
        $order->matched_notional = $matchedNotional;
        $remaining = bcsub((string) $order->limit_order_notional, $matchedNotional, 8);
        $order->remaining_notional = bccomp($remaining, '0', 8) === -1 ? '0' : $remaining;

        $avgFillPrice = (string) ($remote['avg_price'] ?? $remote['avgPrice'] ?? '');
        $order->avg_fill_price = $avgFillPrice === '' ? null : $avgFillPrice;
        $order->snapshot = array_merge($order->snapshot ?? [], ['last_remote_order' => $remote]);

        // 优先消费远端 status，避免只靠 matched_notional 推断本地状态。
        $remoteStatus = strtoupper(trim((string) ($remote['status'] ?? '')));
        if ($remoteStatus === 'MATCHED') {
            $order->remaining_notional = '0';
            $order->status = PmSkipRoundOrder::STATUS_FILLED;
        } elseif ($remoteStatus === 'CANCELED') {
            $order->cancel_confirmed_at = $order->cancel_confirmed_at ?: now();
            $order->status = bccomp((string) $order->matched_notional, '0', 8) === 1
                ? PmSkipRoundOrder::STATUS_FILLED
                : PmSkipRoundOrder::STATUS_CANCEL_REQUESTED;
        } elseif (bccomp((string) $order->matched_notional, '0', 8) === 1) {
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
