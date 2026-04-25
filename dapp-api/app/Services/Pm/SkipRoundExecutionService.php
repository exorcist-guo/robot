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
        $limitOrderSize = bcdiv((string) $order->bet_amount, $limitPrice, 2);

        $order->place_started_at = $order->place_started_at ?: now();
        $order->limit_price = $limitPrice;
        $order->limit_order_size = $limitOrderSize;
        $order->limit_order_notional = (string) $order->bet_amount;
        $order->remaining_notional = (string) $order->bet_amount;
        $order->snapshot = array_merge($order->snapshot ?? [], ['initial_orderbook' => $book, 'selected_level' => $level]);
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
        $this->refreshRemoteOrder($wallet, $order);
        $order->refresh();

        if (bccomp((string) $order->remaining_notional, '0', 8) <= 0) {
            $order->status = PmSkipRoundOrder::STATUS_FILLED;
            $order->save();
            return $order;
        }

        if (time() < ($currentRoundEnd - 5)) {
            return $order;
        }

        if ($order->remote_order_id && $order->cancel_requested_at === null) {
            $order->cancel_requested_at = now();
            $order->status = PmSkipRoundOrder::STATUS_CANCEL_REQUESTED;
            $order->save();
            $cancelResponse = $this->cancelOrderService->cancel($wallet, (string) $order->remote_order_id);
            $order->snapshot = array_merge($order->snapshot ?? [], ['cancel_response' => $cancelResponse]);
            $order->save();
        }

        return $this->advanceCanceledOrder($wallet, $order->fresh());
    }

    private function advanceCanceledOrder(PmCustodyWallet $wallet, PmSkipRoundOrder $order): PmSkipRoundOrder
    {
        if ($order->remote_order_id) {
            $this->refreshRemoteOrder($wallet, $order);
            $order->refresh();
            $order->cancel_confirmed_at = $order->cancel_confirmed_at ?: now();
            $order->save();
        }

        if (bccomp((string) $order->remaining_notional, '0', 8) <= 0) {
            $order->status = PmSkipRoundOrder::STATUS_FILLED;
            $order->save();
            return $order;
        }

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
        $avgFillPrice = (string) ($remote['avg_price'] ?? $remote['avgPrice'] ?? '');
        $order->avg_fill_price = $avgFillPrice === '' ? null : $avgFillPrice;
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
