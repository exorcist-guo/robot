<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmCustodyWallet;
use App\Models\Pm\PmOrderIntent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;

class IntentExecutionPrecheckService
{
    public function __construct(private readonly PolymarketTradingService $trading)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function evaluate(PmOrderIntent $intent, PmCustodyWallet $wallet, ?PmCopyTask $copyTask = null): array
    {
        $riskSnapshot = is_array($intent->risk_snapshot) ? $intent->risk_snapshot : [];
        $contextMarketId = (string) ($intent->leaderTrade?->market_id ?? ($riskSnapshot['market_id'] ?? ''));
        $contextOutcome = (string) ($intent->leaderTrade?->raw['outcome'] ?? ($riskSnapshot['trigger_side'] ?? ''));

        if (!$intent->token_id) {
            return $this->failure('missing_token_id', ['intent_id' => $intent->id]);
        }

        try {
            $cachedBook = $this->trading->getOrderBook((string) $intent->token_id);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'No orderbook exists for the requested token id')) {
                return $this->failure('token_not_tradable', [
                    'intent_id' => $intent->id,
                    'token_id' => (string) $intent->token_id,
                    'market_id' => $contextMarketId,
                    'outcome' => $contextOutcome,
                ]);
            }

            throw $e;
        }

        if (!is_array($cachedBook) || $cachedBook === []) {
            return $this->failure('token_not_tradable', [
                'intent_id' => $intent->id,
                'token_id' => (string) $intent->token_id,
                'market_id' => $contextMarketId,
                'outcome' => $contextOutcome,
            ]);
        }

        // leader_price 仅用于记录 leader 成交参考价；跟单实际下单按当时 orderbook 市价执行。
        $leaderPrice = (string) ($intent->leader_price ?: '0');

        $side = strtoupper((string) $intent->side);
        if (!in_array($side, [PolymarketTradingService::SIDE_BUY, PolymarketTradingService::SIDE_SELL], true)) {
            return $this->failure('invalid_side', ['side' => $side]);
        }

        if ((int) $intent->clamped_usdc <= 0) {
            return $this->failure('invalid_clamped_usdc', ['clamped_usdc' => (int) $intent->clamped_usdc]);
        }

        $usdc = BigDecimal::of((string) $intent->clamped_usdc)
            ->dividedBy('1000000', 6, RoundingMode::DOWN);

        $marketPriceQuote = $this->trading->getOrderBookMarketPrice(
            (string) $intent->token_id,
            $side,
            $side === PolymarketTradingService::SIDE_BUY ? $usdc->__toString() : '0',
            null,
            $cachedBook
        );

        $executionPrice = (string) ($marketPriceQuote['price'] ?? '0');
        if (bccomp($executionPrice, '0', 8) <= 0) {
            return $this->failure('missing_best_price', [
                'quote' => $marketPriceQuote,
                'leader_price' => $leaderPrice,
            ]);
        }

        $sizeScale = $side === PolymarketTradingService::SIDE_BUY ? 2 : 4;
        $size = $usdc->dividedBy($executionPrice, $sizeScale, RoundingMode::DOWN);
        $minOrderSize = isset($marketPriceQuote['min_size']) && preg_match('/^\d+(\.\d+)?$/', (string) $marketPriceQuote['min_size'])
            ? BigDecimal::of((string) $marketPriceQuote['min_size'])
            : null;
        if ($side === PolymarketTradingService::SIDE_BUY && $minOrderSize !== null && $size->isLessThan($minOrderSize)) {
            $size = $minOrderSize;
            $usdc = $minOrderSize->multipliedBy($executionPrice)->toScale(6, RoundingMode::UP);
        }

        if ($size->isLessThanOrEqualTo(BigDecimal::zero())) {
            return $this->failure('invalid_size', ['size' => $size->__toString()]);
        }

        $normalizedPrice = BigDecimal::of($executionPrice)->toScale(4, RoundingMode::DOWN)->stripTrailingZeros()->__toString();
        $normalizedSize = $size->stripTrailingZeros()->__toString();
        $normalizedNotional = BigDecimal::of($normalizedPrice)
            ->multipliedBy($normalizedSize)
            ->toScale(6, RoundingMode::DOWN);

        if ($side === PolymarketTradingService::SIDE_BUY && $normalizedNotional->isLessThan(BigDecimal::of('1'))) {
            return $this->failure('below_min_marketable_buy_amount', [
                'normalized_notional' => $normalizedNotional->__toString(),
            ]);
        }

        $cacheKey = 'wallet_readiness:' . $wallet->id . ':' . $side;
        $cachedReadiness = Cache::get($cacheKey);
        if ($cachedReadiness && ($cachedReadiness['is_ready'] ?? false) === true) {
            $readiness = $cachedReadiness;
        } else {
            $readiness = $this->trading->getTradingReadiness(
                $wallet,
                $side,
                (string) $intent->token_id,
                $normalizedPrice,
                $normalizedSize,
            );
            if (($readiness['is_ready'] ?? false) === true) {
                Cache::put($cacheKey, array_merge($readiness, [
                    'side' => $side,
                    'cached_at' => now()->toDateTimeString(),
                ]), 300);
            }
        }

        if (($readiness['is_ready'] ?? false) !== true) {
            return $this->failure((string) ($readiness['failure_code'] ?? 'trade_not_ready'), [
                'readiness' => $readiness,
                'normalized_price' => $normalizedPrice,
                'normalized_size' => $normalizedSize,
                'normalized_notional' => $normalizedNotional->__toString(),
            ]);
        }

        $dailyMaxUsdc = isset($riskSnapshot['daily_max_usdc']) && is_numeric((string) $riskSnapshot['daily_max_usdc'])
            ? (int) $riskSnapshot['daily_max_usdc']
            : null;
        if ($this->trading->exceedsDailyMaxUsdc((int) $intent->member_id, $dailyMaxUsdc)) {
            return $this->failure('daily_limit_exceeded', ['risk_snapshot' => $riskSnapshot]);
        }

        $slippageAnchorPrice = $executionPrice;
        $slippage = $this->trading->evaluateSlippage(
            (string) $intent->token_id,
            $side,
            $slippageAnchorPrice,
            (int) ($riskSnapshot['max_slippage_bps'] ?? 0),
            $usdc->__toString(),
            $executionPrice,
        );

        if (($slippage['passed'] ?? true) !== true) {
            return $this->failure('slippage_exceeded', [
                'slippage' => $slippage,
                'execution_price' => $executionPrice,
                'leader_price' => $leaderPrice,
            ]);
        }

        $requestPayload = [
            'token_id' => (string) $intent->token_id,
            'market_id' => $contextMarketId,
            'outcome' => $contextOutcome,
            'side' => $side,
            'price' => $normalizedPrice,
            'size' => $normalizedSize,
            'order_type' => (bool) ($riskSnapshot['allow_partial_fill'] ?? true) ? 'GTC' : 'FOK',
            'defer_exec' => false,
            'expiration' => '0',
            'nonce' => '0',
        ];

        return [
            'ok' => true,
            'cached_book' => $cachedBook,
            'quote' => $marketPriceQuote,
            'leader_price' => $leaderPrice,
            'execution_price' => $executionPrice,
            'normalized_price' => $normalizedPrice,
            'normalized_size' => $normalizedSize,
            'normalized_notional' => $normalizedNotional->__toString(),
            'readiness' => $readiness,
            'slippage' => $slippage,
            'request_payload' => $requestPayload,
            'request_context' => array_filter([
                'intent_id' => $intent->id,
                'member_id' => $intent->member_id,
                'copy_task_id' => $intent->copy_task_id,
                'leader_trade_id' => $intent->leader_trade_id,
                'market_id' => $contextMarketId,
                'outcome' => $contextOutcome,
                'token_id' => (string) $intent->token_id,
                'side' => $side,
                'leader_price' => $leaderPrice,
                'execution_price' => $executionPrice,
                'price_source' => 'orderbook_market_price',
                'normalized_price' => $normalizedPrice,
                'target_usdc' => (string) $intent->target_usdc,
                'clamped_usdc' => (string) $intent->clamped_usdc,
                'size' => $size->__toString(),
                'normalized_size' => $normalizedSize,
                'normalized_notional' => $normalizedNotional->__toString(),
                'risk_snapshot' => $riskSnapshot,
                'slippage' => $slippage,
                'readiness' => $readiness,
            ], static fn ($value) => $value !== null),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function failure(string $reason, array $context = []): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
            'context' => $context,
        ];
    }
}
