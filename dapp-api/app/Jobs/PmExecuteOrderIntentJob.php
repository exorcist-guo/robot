<?php

namespace App\Jobs;

use App\Jobs\PmSyncOrderStatusJob;
use App\Jobs\PmSyncOrderSettlementJob;
use App\Models\Pm\PmOrder;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\IntentExecutionPrecheckService;
use App\Services\Pm\PolymarketTradingService;
use App\Services\Pm\PurchaseTrackingService;
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

    public function handle(
        PolymarketTradingService $trading,
        IntentExecutionPrecheckService $precheckService,
        PurchaseTrackingService $purchaseTrackingService
    ): void {
        $lock = Cache::lock('pm:intent:execute:' . $this->intentId, 120);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return;
        }

        try {
            $this->runHandle($trading, $precheckService, $purchaseTrackingService);
        } finally {
            optional($lock)->release();
        }
    }

    private function runHandle(
        PolymarketTradingService $trading,
        IntentExecutionPrecheckService $precheckService,
        PurchaseTrackingService $purchaseTrackingService
    ): void {
        $intent = PmOrderIntent::with(['copyTask', 'member.custodyWallet.apiCredentials', 'leaderTrade'])->find($this->intentId);
        if (!$intent || $intent->status !== PmOrderIntent::STATUS_PENDING) {
            return;
        }

        $intent->attempt_count = (int) $intent->attempt_count + 1;
        $intent->processing_started_at = now();
        $intent->execution_stage = 'validating';
        $intent->save();

        $copyTask = $intent->copyTask;
        if ($copyTask && $copyTask->mode === \App\Models\Pm\PmCopyTask::MODE_TAIL_SWEEP && $copyTask->status !== 1) {
            $this->markSkipped($intent, 'task_paused', 'task', [
                'task_id' => $copyTask->id,
            ]);
            return;
        }

        $wallet = $intent->member?->custodyWallet;
        if (!$wallet) {
            $this->markFailed($intent, 'wallet_not_ready', 'wallet', []);
            return;
        }

        if (
            strtoupper((string) $intent->side) === PolymarketTradingService::SIDE_SELL
            && $this->autoApproveSellAllowanceIfNeeded($intent, $wallet, $trading)
        ) {
            return;
        }

        $precheck = $precheckService->evaluate($intent, $wallet, $copyTask);
        if (($precheck['ok'] ?? false) !== true) {
            $reason = (string) ($precheck['reason'] ?? 'precheck_failed');
            $context = is_array($precheck['context'] ?? null) ? $precheck['context'] : [];
            $category = $this->mapReasonToCategory($reason);

            if (in_array($reason, ['trade_not_ready'], true) || str_ends_with($reason, '_balance') || str_contains($reason, 'allowance')) {
                $this->markFailed($intent, $reason, $category, $context, PmOrder::STATUS_REJECTED, 'validation');
            } else {
                $this->markSkipped($intent, $reason, $category, $context);
            }
            return;
        }

        $requestPayload = $precheck['request_payload'];
        $requestContext = $precheck['request_context'];
        $side = (string) $requestPayload['side'];
        $normalizedPrice = (string) $precheck['normalized_price'];
        $normalizedNotional = (string) $precheck['normalized_notional'];

        $intent->decision_payload = array_merge(
            is_array($intent->decision_payload) ? $intent->decision_payload : [],
            ['precheck' => $precheck]
        );
        $intent->execution_stage = 'submitting';
        $intent->save();

        if ((bool) config('pm.copy_dry_run', false)) {
            PmOrder::updateOrCreate(
                ['order_intent_id' => $intent->id],
                [
                    'leader_role' => $intent->leader_role,
                    'token_id' => $intent->token_id,
                    'status' => PmOrder::STATUS_REJECTED,
                    'request_payload' => array_merge($requestContext, ['request' => $requestPayload]),
                    'response_payload' => ['dry_run' => true],
                    'error_code' => 'dry_run_enabled',
                    'error_message' => 'dry_run_enabled',
                    'failure_category' => 'dry_run',
                    'is_retryable' => false,
                    'retry_count' => 0,
                    'last_sync_at' => now(),
                ]
            );

            $intent->execution_mode = 'dry_run';
            $intent->execution_stage = 'simulated';
            $intent->processed_at = now();
            $intent->decision_payload = array_merge(
                is_array($intent->decision_payload) ? $intent->decision_payload : [],
                ['simulation' => ['request_payload' => $requestPayload, 'request_context' => $requestContext]]
            );
            $intent->skip_reason = 'dry_run_enabled';
            $intent->skip_category = 'dry_run';
            $intent->status = PmOrderIntent::STATUS_SKIPPED;
            $intent->save();
            return;
        }

        try {
            $result = $trading->placeOrder($wallet, $requestPayload);

            $remoteStatus = $this->mapRemoteStatus($result['response'] ?? []);
            $filledUsdc = $this->resolveFilledUsdc($side, $normalizedNotional, $result['response'] ?? []);
            $conditionId = $result['response']['market'] ?? null;
            $settlementPayload = $conditionId ? ['condition_id' => $conditionId] : null;

            $savedOrder = PmOrder::updateOrCreate(
                ['order_intent_id' => $intent->id],
                [
                    'leader_role' => $intent->leader_role,
                    'token_id' => $intent->token_id,
                    'poly_order_id' => $result['response']['id'] ?? $result['response']['orderID'] ?? null,
                    'status' => $remoteStatus,
                    'request_payload' => array_merge($requestContext, ['request' => $result['request']]),
                    'response_payload' => $result['response'],
                    'submitted_at' => now(),
                    'last_sync_at' => now(),
                    'filled_usdc' => $filledUsdc,
                    'avg_price' => $normalizedPrice,
                    'exchange_nonce' => (string) ($result['request']['payload']['order']['nonce'] ?? '0'),
                    'settlement_payload' => $settlementPayload,
                    'failure_category' => null,
                    'is_retryable' => false,
                    'retry_count' => 0,
                ]
            );

            if (in_array($remoteStatus, [PmOrder::STATUS_FILLED, PmOrder::STATUS_PARTIAL], true)) {
                $purchaseTrackingService->recordBuyOrder($savedOrder);
            }

            if ($side === PolymarketTradingService::SIDE_SELL && in_array($remoteStatus, [PmOrder::STATUS_FILLED, PmOrder::STATUS_PARTIAL], true)) {
                $filledSize = $savedOrder->response_payload['size_matched'] ?? $savedOrder->response_payload['filled_size'] ?? $requestContext['normalized_size'] ?? '0';
                if (is_string($filledSize) || is_numeric($filledSize)) {
                    $allocation = $purchaseTrackingService->allocateForSell($savedOrder, (string) $filledSize);
                    $savedOrder->request_payload = array_merge(is_array($savedOrder->request_payload) ? $savedOrder->request_payload : [], [
                        'sell_allocation' => $allocation,
                    ]);
                    $savedOrder->save();
                }
            }

            if (in_array($remoteStatus, [PmOrder::STATUS_SUBMITTED, PmOrder::STATUS_PARTIAL], true)) {
                PmSyncOrderStatusJob::dispatch($savedOrder->id)->delay(now()->addSeconds(3));
            }
            if (in_array($remoteStatus, [PmOrder::STATUS_FILLED, PmOrder::STATUS_PARTIAL], true)) {
                PmSyncOrderSettlementJob::dispatch($savedOrder->id, false, false)->delay(now()->addSeconds(5));
            }

            $intent->status = PmOrderIntent::STATUS_SUBMITTED;
            $intent->skip_reason = null;
            $intent->skip_category = null;
            $intent->last_error_code = null;
            $intent->last_error_message = null;
            $intent->processed_at = now();
            $intent->execution_mode = 'live';
            $intent->execution_stage = 'submitted';
            $intent->decision_payload = array_merge(
                is_array($intent->decision_payload) ? $intent->decision_payload : [],
                ['submit_result' => ['remote_status' => $remoteStatus, 'poly_order_id' => $savedOrder->poly_order_id]]
            );
            $intent->save();
        } catch (\Throwable $e) {
            [$failureCategory, $isRetryable] = $this->classifyException($e->getMessage());
            PmOrder::updateOrCreate(
                ['order_intent_id' => $intent->id],
                [
                    'leader_role' => $intent->leader_role,
                    'token_id' => $intent->token_id,
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
            $intent->skip_category = $failureCategory;
            $intent->last_error_code = (string) ($e->getCode() ?: 'submit_failed');
            $intent->last_error_message = $e->getMessage();
            $intent->processed_at = now();
            $intent->execution_mode = 'live';
            $intent->execution_stage = 'failed';
            $intent->decision_payload = array_merge(
                is_array($intent->decision_payload) ? $intent->decision_payload : [],
                ['submit_error' => ['message' => $e->getMessage(), 'category' => $failureCategory]]
            );
            $intent->save();

            if ($isRetryable && $this->attempts() < $this->tries) {
                $this->release($this->backoff()[min($this->attempts() - 1, count($this->backoff()) - 1)]);
                return;
            }
        }
    }

    private function autoApproveSellAllowanceIfNeeded(
        PmOrderIntent $intent,
        \App\Models\Pm\PmCustodyWallet $wallet,
        PolymarketTradingService $trading
    ): bool {
        $tokenId = trim((string) $intent->token_id);
        if ($tokenId === '') {
            return false;
        }

        $status = $trading->getConditionalAllowanceStatus($wallet, $tokenId);
        if (($status['is_approved'] ?? false) === true) {
            return false;
        }

        $decisionPayload = is_array($intent->decision_payload) ? $intent->decision_payload : [];
        $autoApprove = is_array($decisionPayload['auto_approve_sell'] ?? null) ? $decisionPayload['auto_approve_sell'] : [];
        $submitted = is_array($autoApprove['submitted'] ?? null) ? $autoApprove['submitted'] : [];
        if ($submitted !== []) {
            $intent->execution_stage = 'waiting_approval';
            $intent->decision_payload = array_merge($decisionPayload, [
                'auto_approve_sell' => array_merge($autoApprove, [
                    'status' => $status,
                    'waiting' => true,
                    'checked_at' => now()->toDateTimeString(),
                ]),
            ]);
            $intent->save();
            $this->release(15);
            return true;
        }

        $approval = $trading->approveForSide($wallet, PolymarketTradingService::SIDE_SELL, $tokenId);
        try {
            \Illuminate\Support\Facades\Cache::forget('wallet_readiness:' . $wallet->id . ':' . PolymarketTradingService::SIDE_SELL . ':' . $tokenId);
        } catch (\Throwable) {
        }

        $intent->execution_stage = 'waiting_approval';
        $intent->decision_payload = array_merge($decisionPayload, [
            'auto_approve_sell' => [
                'submitted' => $approval,
                'status' => $status,
                'checked_at' => now()->toDateTimeString(),
            ],
        ]);
        $intent->save();
        $this->release(15);
        return true;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function markSkipped(PmOrderIntent $intent, string $reason, string $category, array $context, int $orderStatus = PmOrder::STATUS_REJECTED): void
    {
        $intent->status = PmOrderIntent::STATUS_SKIPPED;
        $intent->skip_reason = $reason;
        $intent->skip_category = $category;
        $intent->last_error_code = $reason;
        $intent->last_error_message = $reason;
        $intent->processed_at = now();
        $intent->execution_stage = 'skipped';
        $intent->decision_payload = array_merge(is_array($intent->decision_payload) ? $intent->decision_payload : [], [
            'skip' => ['reason' => $reason, 'category' => $category, 'context' => $context],
        ]);
        $intent->save();

        PmOrder::updateOrCreate(
            ['order_intent_id' => $intent->id],
            [
                'leader_role' => $intent->leader_role,
                'token_id' => $intent->token_id,
                'status' => $orderStatus,
                'request_payload' => $context,
                'response_payload' => null,
                'error_code' => $reason,
                'error_message' => $reason,
                'failure_category' => $category,
                'is_retryable' => false,
                'retry_count' => max(0, $intent->attempt_count - 1),
                'last_sync_at' => now(),
            ]
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    private function markFailed(
        PmOrderIntent $intent,
        string $reason,
        string $category,
        array $context,
        int $orderStatus = PmOrder::STATUS_ERROR,
        ?string $failureCategory = null
    ): void {
        $intent->status = PmOrderIntent::STATUS_FAILED;
        $intent->skip_reason = $reason;
        $intent->skip_category = $category;
        $intent->last_error_code = $reason;
        $intent->last_error_message = $reason;
        $intent->processed_at = now();
        $intent->execution_stage = 'failed';
        $intent->decision_payload = array_merge(is_array($intent->decision_payload) ? $intent->decision_payload : [], [
            'failure' => ['reason' => $reason, 'category' => $category, 'context' => $context],
        ]);
        $intent->save();

        PmOrder::updateOrCreate(
            ['order_intent_id' => $intent->id],
            [
                'leader_role' => $intent->leader_role,
                'token_id' => $intent->token_id,
                'status' => $orderStatus,
                'request_payload' => $context,
                'response_payload' => null,
                'error_code' => $reason,
                'error_message' => $reason,
                'failure_category' => $failureCategory ?? $category,
                'is_retryable' => false,
                'retry_count' => max(0, $intent->attempt_count - 1),
                'last_sync_at' => now(),
            ]
        );
    }

    private function mapReasonToCategory(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'allowance') => 'allowance',
            str_contains($reason, 'balance') => 'balance',
            str_contains($reason, 'slippage') => 'slippage',
            str_contains($reason, 'token') => 'market',
            str_contains($reason, 'task') => 'task',
            default => 'validation',
        };
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
        $matchedSize = null;
        foreach (['size_matched', 'filled_size', 'matched_size', 'sizeMatched'] as $key) {
            $value = $response[$key] ?? null;
            if (is_string($value) || is_int($value) || is_float($value)) {
                $matchedSize = (string) $value;
                break;
            }
        }
        $hasMatchedSize = $matchedSize !== null && preg_match('/^\d+(\.\d+)?$/', $matchedSize) && bccomp($matchedSize, '0', 8) > 0;

        return match (true) {
            in_array($status, ['matched', 'filled'], true) => PmOrder::STATUS_FILLED,
            in_array($status, ['partially_matched', 'partial', 'partially_filled'], true) => PmOrder::STATUS_PARTIAL,
            in_array($status, ['canceled', 'cancelled', 'canceled_market_resolved', 'cancelled_market_resolved'], true) && $hasMatchedSize => PmOrder::STATUS_FILLED,
            in_array($status, ['canceled', 'cancelled'], true) => PmOrder::STATUS_CANCELED,
            $status === 'rejected' => PmOrder::STATUS_REJECTED,
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
