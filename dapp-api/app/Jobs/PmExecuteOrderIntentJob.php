<?php

namespace App\Jobs;

use App\Models\Pm\PmOrder;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\PolymarketTradingService;
use App\Jobs\PmSyncOrderStatusJob;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class PmExecuteOrderIntentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $intentId)
    {
    }

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    public function handle(PolymarketTradingService $trading): void
    {
        $lock = Cache::lock('pm:intent:execute:' . $this->intentId, 120);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return;
        }

        try {
            $this->runHandle($trading);
        } finally {
            optional($lock)->release();
        }
    }

    private function runHandle(PolymarketTradingService $trading): void
    {
        $intent = PmOrderIntent::with(['copyTask', 'member.custodyWallet.apiCredentials', 'leaderTrade'])->find($this->intentId);
        if (!$intent || $intent->status !== PmOrderIntent::STATUS_PENDING) {
            return;
        }

        $intent->attempt_count = (int) $intent->attempt_count + 1;
        $intent->save();

        $riskSnapshot = is_array($intent->risk_snapshot) ? $intent->risk_snapshot : [];
        $copyTask = $intent->copyTask;
        if ($copyTask && $copyTask->mode === \App\Models\Pm\PmCopyTask::MODE_TAIL_SWEEP && $copyTask->status !== 1) {
            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->skip_reason = 'task_paused';
            $intent->save();
            return;
        }

        $wallet = $intent->member?->custodyWallet;
        if (!$wallet) {
            $intent->status = PmOrderIntent::STATUS_FAILED;
            $intent->skip_reason = 'wallet_not_ready';
            $intent->save();
            return;
        }

        if (!$intent->token_id) {
            $intent->status = PmOrderIntent::STATUS_FAILED;
            $intent->skip_reason = 'missing_token_id';
            $intent->save();
            return;
        }

        $contextMarketId = (string) ($intent->leaderTrade?->market_id ?? ($riskSnapshot['market_id'] ?? ''));
        $contextOutcome = (string) ($intent->leaderTrade?->raw['outcome'] ?? ($riskSnapshot['trigger_side'] ?? ''));

        if (!$trading->isTokenTradable((string) $intent->token_id)) {
            PmOrder::updateOrCreate(
                ['order_intent_id' => $intent->id],
                [
                    'status' => PmOrder::STATUS_ERROR,
                    'request_payload' => [
                        'intent_id' => $intent->id,
                        'member_id' => $intent->member_id,
                        'copy_task_id' => $intent->copy_task_id,
                        'leader_trade_id' => $intent->leader_trade_id,
                        'market_id' => $contextMarketId,
                        'outcome' => $contextOutcome,
                        'token_id' => (string) $intent->token_id,
                    ],
                    'response_payload' => null,
                    'error_code' => 'token_not_tradable',
                    'error_message' => 'token_not_tradable',
                    'last_sync_at' => now(),
                ]
            );

            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->skip_reason = 'token_not_tradable';
            $intent->save();
            return;
        }

        $leaderPrice = (string) ($intent->leader_price ?: '0');
        if (bccomp($leaderPrice, '0', 8) <= 0) {
            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->skip_reason = 'invalid_price';
            $intent->save();
            return;
        }

        $side = strtoupper((string) $intent->side);
        if (!in_array($side, [PolymarketTradingService::SIDE_BUY, PolymarketTradingService::SIDE_SELL], true)) {
            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->skip_reason = 'invalid_side';
            $intent->save();
            return;
        }

        if ((int) $intent->clamped_usdc <= 0) {
            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->skip_reason = 'invalid_clamped_usdc';
            $intent->save();
            return;
        }

        $bestPrice = $trading->getOrderBookBestPrice((string) $intent->token_id, $side);
        $executionPrice = (string) ($bestPrice['price'] ?? '0');
        if (bccomp($executionPrice, '0', 8) <= 0) {
            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->skip_reason = 'missing_best_price';
            $intent->last_error_code = 'missing_best_price';
            $intent->last_error_message = 'missing_best_price';
            $intent->save();

            PmOrder::updateOrCreate(
                ['order_intent_id' => $intent->id],
                [
                    'status' => PmOrder::STATUS_REJECTED,
                    'request_payload' => [
                        'intent_id' => $intent->id,
                        'member_id' => $intent->member_id,
                        'copy_task_id' => $intent->copy_task_id,
                        'leader_trade_id' => $intent->leader_trade_id,
                        'market_id' => $contextMarketId,
                        'outcome' => $contextOutcome,
                        'token_id' => (string) $intent->token_id,
                        'side' => $side,
                        'leader_price' => $leaderPrice,
                        'price_source' => 'orderbook_best_price',
                        'book' => $bestPrice['book'] ?? [],
                    ],
                    'response_payload' => null,
                    'error_code' => 'missing_best_price',
                    'error_message' => 'missing_best_price',
                    'failure_category' => 'validation',
                    'is_retryable' => false,
                    'retry_count' => max(0, $intent->attempt_count - 1),
                    'last_sync_at' => now(),
                ]
            );
            return;
        }

        $usdc = BigDecimal::of((string) $intent->clamped_usdc)
            ->dividedBy('1000000', 6, RoundingMode::DOWN);
        $sizeScale = $side === PolymarketTradingService::SIDE_BUY ? 2 : 4;
        $size = $usdc->dividedBy($executionPrice, $sizeScale, RoundingMode::DOWN);

        if ($size->isLessThanOrEqualTo(BigDecimal::zero())) {
            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->skip_reason = 'invalid_size';
            $intent->save();
            return;
        }

        $normalizedPrice = BigDecimal::of($executionPrice)->toScale(4, RoundingMode::DOWN)->stripTrailingZeros()->__toString();
        $normalizedSize = $size->stripTrailingZeros()->__toString();
        $normalizedNotional = BigDecimal::of($normalizedPrice)
            ->multipliedBy($normalizedSize)
            ->toScale(6, RoundingMode::DOWN);

        if ($side === PolymarketTradingService::SIDE_BUY && $normalizedNotional->isLessThan(BigDecimal::of('1'))) {
            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->skip_reason = 'below_min_marketable_buy_amount';
            $intent->save();
            return;
        }

        $readiness = $trading->getTradingReadiness(
            $wallet,
            $side,
            (string) $intent->token_id,
            $normalizedPrice,
            $normalizedSize,
        );

        if (($readiness['is_ready'] ?? false) !== true) {
            $failureCode = (string) ($readiness['failure_code'] ?? 'trade_not_ready');
            PmOrder::updateOrCreate(
                ['order_intent_id' => $intent->id],
                [
                    'status' => PmOrder::STATUS_REJECTED,
                    'request_payload' => array_merge($requestContext = array_filter([
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
                        'price_source' => 'orderbook_best_price',
                        'normalized_price' => $normalizedPrice,
                        'target_usdc' => (string) $intent->target_usdc,
                        'clamped_usdc' => (string) $intent->clamped_usdc,
                        'normalized_size' => $normalizedSize,
                        'normalized_notional' => $normalizedNotional->__toString(),
                    ], static fn ($value) => $value !== null), ['readiness' => $readiness]),
                    'response_payload' => null,
                    'error_code' => $failureCode,
                    'error_message' => $failureCode,
                    'failure_category' => 'validation',
                    'is_retryable' => false,
                    'retry_count' => max(0, $intent->attempt_count - 1),
                    'last_sync_at' => now(),
                ]
            );

            $intent->status = PmOrderIntent::STATUS_FAILED;
            $intent->skip_reason = $failureCode;
            $intent->last_error_code = $failureCode;
            $intent->last_error_message = $failureCode;
            $intent->save();
            return;
        }

        $riskSnapshot = is_array($intent->risk_snapshot) ? $intent->risk_snapshot : [];
        $dailyMaxUsdc = isset($riskSnapshot['daily_max_usdc']) && is_numeric((string) $riskSnapshot['daily_max_usdc'])
            ? (int) $riskSnapshot['daily_max_usdc']
            : null;
        if ($trading->exceedsDailyMaxUsdc((int) $intent->member_id, $dailyMaxUsdc)) {
            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->skip_reason = 'daily_limit_exceeded';
            $intent->last_error_code = 'daily_limit_exceeded';
            $intent->last_error_message = 'daily_limit_exceeded';
            $intent->save();

            PmOrder::updateOrCreate(
                ['order_intent_id' => $intent->id],
                [
                    'status' => PmOrder::STATUS_REJECTED,
                    'request_payload' => ['risk_snapshot' => $riskSnapshot],
                    'response_payload' => null,
                    'error_code' => 'daily_limit_exceeded',
                    'error_message' => 'daily_limit_exceeded',
                    'failure_category' => 'risk',
                    'is_retryable' => false,
                    'retry_count' => max(0, $intent->attempt_count - 1),
                    'last_sync_at' => now(),
                ]
            );
            return;
        }

        $slippage = $trading->evaluateSlippage(
            (string) $intent->token_id,
            $side,
            $leaderPrice,
            (int) ($riskSnapshot['max_slippage_bps'] ?? 0),
        );
        if (($slippage['passed'] ?? true) !== true) {
            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->skip_reason = 'slippage_exceeded';
            $intent->last_error_code = 'slippage_exceeded';
            $intent->last_error_message = 'slippage_exceeded';
            $intent->save();

            PmOrder::updateOrCreate(
                ['order_intent_id' => $intent->id],
                [
                    'status' => PmOrder::STATUS_REJECTED,
                    'request_payload' => [
                        'slippage' => $slippage,
                        'risk_snapshot' => $riskSnapshot,
                        'leader_price' => $leaderPrice,
                        'execution_price' => $executionPrice,
                        'price_source' => 'orderbook_best_price',
                    ],
                    'response_payload' => null,
                    'error_code' => 'slippage_exceeded',
                    'error_message' => 'slippage_exceeded',
                    'failure_category' => 'risk',
                    'is_retryable' => false,
                    'retry_count' => max(0, $intent->attempt_count - 1),
                    'last_sync_at' => now(),
                ]
            );
            return;
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

        $requestContext = array_filter([
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
            'price_source' => 'orderbook_best_price',
            'normalized_price' => $normalizedPrice,
            'target_usdc' => (string) $intent->target_usdc,
            'clamped_usdc' => (string) $intent->clamped_usdc,
            'size' => $size->__toString(),
            'normalized_size' => $normalizedSize,
            'normalized_notional' => $normalizedNotional->__toString(),
            'risk_snapshot' => $riskSnapshot,
            'slippage' => $slippage,
        ], static fn ($value) => $value !== null);

        try {
            $result = $trading->placeOrder($wallet, $requestPayload);

            $remoteStatus = $this->mapRemoteStatus($result['response'] ?? []);
            $filledUsdc = $this->resolveFilledUsdc($side, $normalizedNotional->__toString(), $result['response'] ?? []);

            $savedOrder = PmOrder::updateOrCreate(
                ['order_intent_id' => $intent->id],
                [
                    'poly_order_id' => $result['response']['id'] ?? $result['response']['orderID'] ?? null,
                    'status' => $remoteStatus,
                    'request_payload' => array_merge($requestContext, ['request' => $result['request']]),
                    'response_payload' => $result['response'],
                    'submitted_at' => now(),
                    'last_sync_at' => now(),
                    'filled_usdc' => $filledUsdc,
                    'avg_price' => $normalizedPrice,
                    'exchange_nonce' => (string) ($result['request']['payload']['order']['nonce'] ?? '0'),
                    'failure_category' => null,
                    'is_retryable' => false,
                    'retry_count' => 0,
                ]
            );

            if (in_array($remoteStatus, [PmOrder::STATUS_SUBMITTED, PmOrder::STATUS_PARTIAL], true)) {
                PmSyncOrderStatusJob::dispatch($savedOrder->id)->delay(now()->addSeconds(3));
            }

            $intent->status = PmOrderIntent::STATUS_SUBMITTED;
            $intent->skip_reason = null;
            $intent->last_error_code = null;
            $intent->last_error_message = null;
            $intent->save();
        } catch (\Throwable $e) {
            [$failureCategory, $isRetryable] = $this->classifyException($e->getMessage());
            PmOrder::updateOrCreate(
                ['order_intent_id' => $intent->id],
                [
                    'status' => PmOrder::STATUS_ERROR,
                    'request_payload' => array_merge($requestContext, ['request' => $requestPayload]),
                    'response_payload' => null,
                    'error_code' => (string) ($e->getCode() ?: 'submit_failed'),
                    'error_message' => $e->getMessage(),
                    'failure_category' => $failureCategory,
                    'is_retryable' => $isRetryable,
                    'retry_count' => max(0, $this->attempts() - 1),
                    'last_sync_at' => now(),
                ]
            );

            $intent->status = PmOrderIntent::STATUS_FAILED;
            $intent->skip_reason = 'submit_failed';
            $intent->last_error_code = (string) ($e->getCode() ?: 'submit_failed');
            $intent->last_error_message = $e->getMessage();
            $intent->save();

            if ($isRetryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]);
                return;
            }
        }
    }

    /**
     * @return array{0:string,1:bool}
     */
    private function classifyException(string $message): array
    {
        $message = strtolower($message);

        return match (true) {
            str_contains($message, 'allowance') => ['allowance', false],
            str_contains($message, 'balance') => ['balance', false],
            str_contains($message, 'nonce') => ['nonce', false],
            str_contains($message, 'timed out'), str_contains($message, 'timeout'), str_contains($message, 'server error') => ['network', true],
            str_contains($message, '429'), str_contains($message, 'temporarily'), str_contains($message, 'service unavailable') => ['remote', true],
            default => ['remote', false],
        };
    }

    /**
     * @param array<string,mixed> $response
     */
    private function mapRemoteStatus(array $response): int
    {
        $status = strtolower((string) ($response['status'] ?? ''));

        return match ($status) {
            'matched', 'filled' => PmOrder::STATUS_FILLED,
            'partially_matched', 'partial', 'partially_filled' => PmOrder::STATUS_PARTIAL,
            'canceled', 'cancelled' => PmOrder::STATUS_CANCELED,
            'rejected' => PmOrder::STATUS_REJECTED,
            default => PmOrder::STATUS_SUBMITTED,
        };
    }

    /**
     * @param array<string,mixed> $response
     */
    private function resolveFilledUsdc(string $side, string $normalizedNotional, array $response): int
    {
        $status = $this->mapRemoteStatus($response);
        if (!in_array($status, [PmOrder::STATUS_FILLED, PmOrder::STATUS_PARTIAL], true)) {
            return 0;
        }

        $candidate = $side === PolymarketTradingService::SIDE_BUY
            ? (string) ($response['makingAmount'] ?? $normalizedNotional)
            : (string) ($response['takingAmount'] ?? $normalizedNotional);

        if (!preg_match('/^\d+(\.\d+)?$/', $candidate)) {
            $candidate = $normalizedNotional;
        }

        return (int) BigDecimal::of($candidate)
            ->multipliedBy('1000000')
            ->toScale(0, RoundingMode::DOWN)
            ->__toString();
    }
}
