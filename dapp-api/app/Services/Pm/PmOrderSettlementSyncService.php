<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Throwable;

class PmOrderSettlementSyncService
{
    public function __construct(
        private readonly PolymarketTradingService $trading,
        private readonly PolymarketClientFactory $factory,
        private readonly GammaClient $gamma,
        private readonly PolygonRpcService $polygonRpc,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function sync(PmOrder $order, bool $queueClaim = false, bool $dryRun = false): array
    {
        $order->loadMissing('intent.copyTask.member.custodyWallet.apiCredentials');

        $wallet = $order->intent?->member?->custodyWallet;
        if (!$wallet || !$order->poly_order_id) {
            return ['updated' => false, 'reason' => 'missing_wallet_or_poly_order_id'];
        }

        $remote = $this->fetchRemoteOrderSnapshot($order, $wallet);
        $snapshot = $this->buildSettlementSnapshot($order, $remote);
        $snapshot = $this->syncClaimReceiptState($order, $snapshot);
        $snapshot = $this->preserveConfirmedClaimWithoutSettlement($order, $snapshot, $remote);
        $snapshot = $this->normalizeClaimState($snapshot);

        if ($dryRun) {
            return ['updated' => false, 'dry_run' => true, 'snapshot' => $snapshot, 'remote' => $remote];
        }

        $order->status = $snapshot['local_status'];
        $order->response_payload = $remote;
        $syncedFilledUsdc = (int) $snapshot['filled_usdc'];
        $currentFilledUsdc = (int) $order->filled_usdc;
        $order->filled_usdc = $currentFilledUsdc <= 0 ? $syncedFilledUsdc : max($currentFilledUsdc, $syncedFilledUsdc);
        $order->avg_price = $order->avg_price ?: ($snapshot['order_price'] ?: ($order->request_payload['normalized_price'] ?? null));
        $order->original_size = $snapshot['original_size'];
        $order->filled_size = $snapshot['filled_size'];
        $order->order_price = $snapshot['order_price'];
        $order->outcome = $snapshot['outcome'];
        $order->order_type = $snapshot['order_type'];
        $order->remote_order_status = $snapshot['remote_order_status'];
        $order->is_settled = $snapshot['is_settled'];
        $order->settled_at = $snapshot['settled_at'];
        $order->winning_outcome = $snapshot['winning_outcome'];
        $order->settlement_source = $snapshot['settlement_source'];
        $order->position_notional_usdc = $snapshot['position_notional_usdc'];
        $order->pnl_usdc = $snapshot['pnl_usdc'];
        $order->profit_usdc = $snapshot['profit_usdc'];
        $order->roi_bps = $snapshot['roi_bps'];
        $order->is_win = $snapshot['is_win'];
        $order->last_profit_sync_at = now();
        $order->claimable_usdc = $snapshot['claimable_usdc'];
        $order->claim_status = $snapshot['claim_status'];
        $order->settlement_payload = $snapshot['settlement_payload'];
        $order->claim_last_checked_at = now();
        if (array_key_exists('claim_tx_hash', $snapshot)) {
            $order->claim_tx_hash = $snapshot['claim_tx_hash'];
        }
        if (array_key_exists('claim_payload', $snapshot)) {
            $order->claim_payload = $snapshot['claim_payload'];
        }
        if (array_key_exists('claim_completed_at', $snapshot)) {
            $order->claim_completed_at = $snapshot['claim_completed_at'];
        }
        if (array_key_exists('claim_last_error', $snapshot)) {
            $order->claim_last_error = $snapshot['claim_last_error'];
        }
        $order->last_sync_at = now();
        $order->save();

        $this->syncTailSweepPayload($order, $snapshot);

        if ($queueClaim && $snapshot['claim_status'] === PmOrder::CLAIM_STATUS_PENDING) {
            \App\Jobs\PmAutoClaimOrderWinningsJob::dispatch($order->id);
        }

        return ['updated' => true, 'snapshot' => $snapshot, 'remote' => $remote];
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchRemoteOrderSnapshot(PmOrder $order, mixed $wallet): array
    {
        try {
            $remote = $this->trading->getUserOrder($wallet, (string) $order->poly_order_id);
            if (is_array($remote) && $remote !== []) {
                return $remote;
            }
        } catch (Throwable) {
        }

        $stored = is_array($order->response_payload) ? $order->response_payload : [];
        $request = is_array($order->request_payload) ? $order->request_payload : [];

        return [
            'id' => $order->poly_order_id,
            'status' => $stored['status'] ?? $stored['remote_order_status'] ?? 'matched',
            'market' => $this->resolveConditionId($order),
            'asset_id' => $request['token_id'] ?? $request['request']['input']['token_id'] ?? $order->intent?->token_id,
            'side' => $request['side'] ?? $request['request']['input']['side'] ?? $order->intent?->side,
            'original_size' => $request['normalized_size'] ?? $request['size'] ?? $request['request']['input']['size'] ?? null,
            'size_matched' => $stored['takingAmount'] ?? $request['normalized_size'] ?? $request['size'] ?? null,
            'price' => $request['normalized_price'] ?? $request['execution_price'] ?? $request['request']['input']['price'] ?? $order->avg_price,
            'outcome' => $request['outcome'] ?? $request['request']['input']['outcome'] ?? null,
            'order_type' => $request['request']['payload']['orderType'] ?? $request['request']['input']['order_type'] ?? 'GTC',
            'transactionsHashes' => $stored['transactionsHashes'] ?? [],
            'fallback' => true,
            'fallback_source' => 'local_payload',
        ];
    }

    /**
     * @param array<string,mixed> $remote
     * @return array<string,mixed>
     */
    public function buildSettlementSnapshot(PmOrder $order, array $remote): array
    {
        $remoteStatus = strtolower((string) ($remote['status'] ?? ''));
        $filledUsdc = $this->extractFilledUsdc($remote);
        $filledSize = $this->pickDecimal([
            $remote['size_matched'] ?? null,
            $remote['filled_size'] ?? null,
            $remote['matched_size'] ?? null,
            $remote['sizeMatched'] ?? null,
        ]);
        $hasMatchedSize = $filledSize !== null && bccomp($filledSize, '0', 8) > 0;
        $localStatus = match (true) {
            in_array($remoteStatus, ['matched', 'filled'], true) => PmOrder::STATUS_FILLED,
            in_array($remoteStatus, ['partially_matched', 'partial', 'partially_filled'], true) => PmOrder::STATUS_PARTIAL,
            in_array($remoteStatus, ['canceled', 'cancelled', 'canceled_market_resolved', 'cancelled_market_resolved'], true) && $hasMatchedSize => PmOrder::STATUS_FILLED,
            in_array($remoteStatus, ['canceled', 'cancelled', 'canceled_market_resolved', 'cancelled_market_resolved'], true) => PmOrder::STATUS_CANCELED,
            $remoteStatus === 'rejected' => PmOrder::STATUS_REJECTED,
            default => PmOrder::STATUS_SUBMITTED,
        };

        $filledSize = $this->pickDecimal([
            $remote['size_matched'] ?? null,
            $remote['filled_size'] ?? null,
            $remote['matched_size'] ?? null,
            $remote['sizeMatched'] ?? null,
        ]);
        $originalSize = $this->pickDecimal([
            $remote['original_size'] ?? null,
            $remote['size'] ?? null,
            $remote['originalSize'] ?? null,
            $order->request_payload['normalized_size'] ?? null,
            $order->request_payload['size'] ?? null,
        ]);
        $orderPrice = $this->pickDecimal([
            $remote['price'] ?? null,
            $remote['avg_price'] ?? null,
            $order->request_payload['normalized_price'] ?? null,
            $order->request_payload['execution_price'] ?? null,
        ]);

        $outcome = $this->normalizeOutcome((string) ($remote['outcome'] ?? ($order->request_payload['outcome'] ?? '')));
        $winningOutcome = null;
        $isSettled = false;
        $settledAt = null;
        $settlementPayload = [];
        $settlementSource = null;

        if (in_array($localStatus, [PmOrder::STATUS_FILLED, PmOrder::STATUS_PARTIAL], true)) {
            [$isSettled, $winningOutcome, $settledAt, $settlementPayload, $settlementSource] = $this->resolveSettlement($order, $remote);
        } elseif (in_array($localStatus, [PmOrder::STATUS_CANCELED, PmOrder::STATUS_REJECTED, PmOrder::STATUS_ERROR], true)) {
            // 已取消、已拒绝、错误状态的订单也标记为已结算（最终状态）
            $isSettled = true;
            $settledAt = now();
        }

        $positionNotionalUsdc = null;
        $pnlUsdc = null;
        $profitUsdc = null;
        $roiBps = null;
        $isWin = null;
        $claimableUsdc = null;
        $claimStatus = PmOrder::CLAIM_STATUS_NOT_NEEDED;

        if ($filledSize !== null && $orderPrice !== null) {
            $positionNotionalUsdc = $this->decimalToUsdc($this->multiplyDecimal($filledSize, $orderPrice));
        }

        if ($isSettled && $winningOutcome !== null && $outcome !== '') {
            $isWin = $winningOutcome === $outcome;
            $pnlUsdc = $this->calculateSettledPnlUsdc($filledSize, $orderPrice, $isWin);
            $profitUsdc = max(0, (int) $pnlUsdc);
            $claimableUsdc = $profitUsdc > 0 ? $profitUsdc : 0;
            if ($positionNotionalUsdc !== null && $positionNotionalUsdc > 0 && $pnlUsdc !== null) {
                $roiBps = (int) BigDecimal::of((string) $pnlUsdc)
                    ->dividedBy((string) $positionNotionalUsdc, 8, RoundingMode::HALF_UP)
                    ->multipliedBy('10000')
                    ->toScale(0, RoundingMode::HALF_UP)
                    ->__toString();
            }
            if ($profitUsdc > 0) {
                $claimStatus = match ((int) $order->claim_status) {
                    PmOrder::CLAIM_STATUS_CLAIMED,
                    PmOrder::CLAIM_STATUS_CLAIMING,
                    PmOrder::CLAIM_STATUS_FAILED,
                    PmOrder::CLAIM_STATUS_SKIPPED => (int) $order->claim_status,
                    default => PmOrder::CLAIM_STATUS_PENDING,
                };
            } else {
                $claimStatus = PmOrder::CLAIM_STATUS_NOT_NEEDED;
            }
        }

        return [
            'local_status' => $localStatus,
            'remote_order_status' => $remoteStatus !== '' ? $remoteStatus : null,
            'filled_usdc' => $filledUsdc,
            'original_size' => $originalSize,
            'filled_size' => $filledSize,
            'order_price' => $orderPrice,
            'outcome' => $outcome !== '' ? $outcome : null,
            'order_type' => (string) ($remote['order_type'] ?? ($order->request_payload['order_type'] ?? '')) ?: null,
            'is_settled' => $isSettled,
            'settled_at' => $settledAt,
            'winning_outcome' => $winningOutcome,
            'settlement_source' => $settlementSource,
            'position_notional_usdc' => $positionNotionalUsdc,
            'pnl_usdc' => $pnlUsdc,
            'profit_usdc' => $profitUsdc,
            'is_profitable' => $profitUsdc !== null && $profitUsdc > 0,
            'roi_bps' => $roiBps,
            'is_win' => $isWin,
            'claimable_usdc' => $claimableUsdc,
            'claim_status' => $claimStatus,
            'settlement_payload' => $settlementPayload,
        ];
    }

    /**
     * @param array<string,mixed> $remote
     * @return array{0:bool,1:?string,2:mixed,3:array<string,mixed>,4:?string}
     */
    private function resolveSettlement(PmOrder $order, array $remote): array
    {
        $intent = $order->intent;
        $conditionId = $this->resolveConditionId($order, $remote);
        if ($conditionId === null) {
            return [false, null, null, [], null];
        }

        $market = $this->fetchResolvedMarket($conditionId, $order);
        if (!is_array($market) || $market === []) {
            return [false, null, null, ['condition_id' => $conditionId], null];
        }

        $tokens = $this->extractMarketTokens($market);
        if ($tokens === []) {
            return [false, null, null, ['condition_id' => $conditionId, 'market' => $market], null];
        }

        $winningOutcome = null;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $winner = $token['winner'] ?? false;
                // winner 可能是 true, 1, "1", "true" 等
                if ($winner === true || $winner === 1 || $winner === '1' || $winner === 'true') {
                    $winningOutcome = $this->normalizeOutcome((string) ($token['outcome'] ?? ''));
                    break;
                }
            }
        }

        $marketEndAt = $this->orderMarketEndAt($order, $market);
        $isSettled = $winningOutcome !== null && ($marketEndAt === null || now()->gte($marketEndAt));

        // 调试日志（无条件输出）
        \Log::info('PmOrderSettlementSync::resolveSettlement', [
            'order_id' => $order->id,
            'winning_outcome' => $winningOutcome,
            'market_end_at' => $marketEndAt?->toDateTimeString(),
            'now' => now()->toDateTimeString(),
            'is_after_end' => $marketEndAt === null ? 'null' : now()->gte($marketEndAt),
            'is_settled' => $isSettled,
            'tokens_count' => count($tokens),
            'tokens' => $tokens,
        ]);

        return [
            $isSettled,
            $winningOutcome,
            $isSettled ? now() : null,
            [
                'condition_id' => $conditionId,
                'market' => $market,
                'market_tokens' => $tokens,
                'remote_order' => $remote,
            ],
            $isSettled ? 'market_winner' : null,
        ];
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function syncClaimReceiptState(PmOrder $order, array $snapshot): array
    {
        $txHash = (string) ($order->claim_tx_hash ?: ($order->claim_payload['tx_hash'] ?? ''));
        if ($txHash === '') {
            return $snapshot;
        }

        try {
            $receipt = $this->polygonRpc->getTransactionReceipt($txHash);
        } catch (Throwable $e) {
            $snapshot['claim_receipt_error'] = $e->getMessage();
            return $snapshot;
        }

        if (!is_array($receipt) || $receipt === []) {
            return $snapshot;
        }

        $receiptStatus = strtolower((string) ($receipt['status'] ?? ''));
        $summary = [
            'status' => $receipt['status'] ?? null,
            'block_number' => $receipt['blockNumber'] ?? null,
            'block_hash' => $receipt['blockHash'] ?? null,
            'gas_used' => $receipt['gasUsed'] ?? null,
            'effective_gas_price' => $receipt['effectiveGasPrice'] ?? null,
            'transaction_index' => $receipt['transactionIndex'] ?? null,
        ];

        $claimPayload = is_array($order->claim_payload) ? $order->claim_payload : [];
        $claimPayload['tx_hash'] = $txHash;
        $claimPayload['receipt'] = $summary;

        if ($receiptStatus === '0x1') {
            $snapshot['claim_status'] = PmOrder::CLAIM_STATUS_CLAIMED;
            $claimPayload['submitted'] = true;
            $claimPayload['already_claimed'] = false;
            $claimPayload['reason'] = 'confirmed';
        } elseif ($receiptStatus === '0x0') {
            $snapshot['claim_status'] = PmOrder::CLAIM_STATUS_FAILED;
            $claimPayload['submitted'] = false;
            $claimPayload['reason'] = 'reverted';
        }

        $snapshot['claim_payload'] = $claimPayload;
        $snapshot['claim_tx_hash'] = $txHash;
        $snapshot['claim_completed_at'] = in_array($snapshot['claim_status'], [PmOrder::CLAIM_STATUS_CLAIMED, PmOrder::CLAIM_STATUS_FAILED], true)
            ? now()
            : null;
        $snapshot['claim_last_error'] = $snapshot['claim_status'] === PmOrder::CLAIM_STATUS_FAILED
            ? '兑奖交易已上链但执行失败'
            : null;

        return $snapshot;
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function syncTailSweepPayload(PmOrder $order, array $snapshot): void
    {
        $intent = $order->intent;
        $task = $intent?->copyTask;
        if (!$intent || !$task || $task->mode !== PmCopyTask::MODE_TAIL_SWEEP || !$snapshot['is_settled']) {
            return;
        }

        $requestPayload = is_array($order->request_payload) ? $order->request_payload : [];
        if (($requestPayload['tail_result_synced'] ?? false) === true) {
            return;
        }

        $triggerSide = (string) ((is_array($intent->risk_snapshot) ? $intent->risk_snapshot : [])['trigger_side'] ?? '');
        $winningOutcome = (string) ($snapshot['winning_outcome'] ?? '');
        if (!in_array($triggerSide, ['up', 'down'], true) || !in_array($winningOutcome, ['up', 'down'], true)) {
            return;
        }

        $isLoss = $winningOutcome !== $triggerSide;
        if ($isLoss) {
            $task->tail_loss_count = (int) $task->tail_loss_count + 1;
            if ($task->tail_loss_stop_count > 0 && $task->tail_loss_count >= $task->tail_loss_stop_count) {
                $task->status = 0;
                $task->tail_loss_stopped_at = now();
            }
            $task->save();
        }

        $requestPayload['tail_result_synced'] = true;
        $requestPayload['tail_is_loss'] = $isLoss;
        $requestPayload['tail_winning_outcome'] = $winningOutcome;
        $order->request_payload = $requestPayload;
        $order->save();
    }

    /**
     * @param array<string,mixed> $remote
     */
    private function extractFilledUsdc(array $remote): int
    {
        $candidates = [
            $remote['takingAmount'] ?? null,
            $remote['taking_amount'] ?? null,
            $remote['filledAmount'] ?? null,
            $remote['filled_amount'] ?? null,
            $remote['usdcAmount'] ?? null,
            $remote['usdc_amount'] ?? null,
            $remote['matchedAmount'] ?? null,
            $remote['matched_amount'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if (preg_match('/^\d+(\.\d+)?$/', $value)) {
                return (int) BigDecimal::of($value)
                    ->multipliedBy('1000000')
                    ->toScale(0, RoundingMode::DOWN)
                    ->__toString();
            }
        }

        $filledSize = $this->pickDecimal([
            $remote['size_matched'] ?? null,
            $remote['filled_size'] ?? null,
            $remote['matched_size'] ?? null,
            $remote['sizeMatched'] ?? null,
        ]);
        $orderPrice = $this->pickDecimal([
            $remote['price'] ?? null,
            $remote['avg_price'] ?? null,
            $remote['initialPrice'] ?? null,
            $remote['initial_price'] ?? null,
        ]);

        if ($filledSize !== null && $orderPrice !== null) {
            return (int) $this->decimalToUsdc($this->multiplyDecimal($filledSize, $orderPrice));
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private function normalizeClaimState(array $snapshot): array
    {
        $profitUsdc = isset($snapshot['profit_usdc']) ? (int) $snapshot['profit_usdc'] : null;
        $claimStatus = isset($snapshot['claim_status']) ? (int) $snapshot['claim_status'] : PmOrder::CLAIM_STATUS_NOT_NEEDED;

        if ($profitUsdc === null || $profitUsdc <= 0) {
            $snapshot['claimable_usdc'] = 0;
            $snapshot['claim_status'] = PmOrder::CLAIM_STATUS_NOT_NEEDED;
            $snapshot['claim_completed_at'] = null;
            $snapshot['claim_last_error'] = null;
            return $snapshot;
        }

        if (!in_array($claimStatus, [
            PmOrder::CLAIM_STATUS_PENDING,
            PmOrder::CLAIM_STATUS_CLAIMING,
            PmOrder::CLAIM_STATUS_CLAIMED,
            PmOrder::CLAIM_STATUS_FAILED,
            PmOrder::CLAIM_STATUS_SKIPPED,
        ], true)) {
            $snapshot['claim_status'] = PmOrder::CLAIM_STATUS_PENDING;
        }

        return $snapshot;
    }

    /**
     * @param array<int,mixed> $tokens
     */
    private function marketMatchesCondition(array $market, string $conditionId, array $tokens = []): bool
    {
        $marketConditionId = strtolower((string) ($market['conditionId'] ?? $market['condition_id'] ?? ''));
        if ($marketConditionId !== '' && $marketConditionId === $conditionId) {
            return true;
        }

        if ($tokens === []) {
            $tokens = $this->extractMarketTokens($market);
        }

        foreach ($tokens as $token) {
            if (is_array($token) && ($token['winner'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function orderMarketEndAt(PmOrder $order, array $market = []): ?Carbon
    {
        $taskMarketEndAt = $order->intent?->copyTask?->market_end_at;
        if ($taskMarketEndAt instanceof Carbon) {
            return $taskMarketEndAt;
        }

        $candidates = [
            $market['endDate'] ?? null,
            $market['end_date'] ?? null,
            $market['closedTime'] ?? null,
            $market['closed_time'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                return Carbon::parse($candidate);
            } catch (Throwable) {
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $remote
     * @return array<string,mixed>
     */
    private function preserveConfirmedClaimWithoutSettlement(PmOrder $order, array $snapshot, array $remote): array
    {
        if (($snapshot['claim_status'] ?? null) !== PmOrder::CLAIM_STATUS_CLAIMED) {
            return $snapshot;
        }

        if (($snapshot['is_settled'] ?? false) === true) {
            return $snapshot;
        }

        $conditionId = $this->resolveConditionId($order, $remote);
        if ($conditionId === null) {
            return $snapshot;
        }

        $market = $this->fetchResolvedMarket($conditionId, $order);
        if (!is_array($market) || $market === []) {
            return $snapshot;
        }

        $tokens = $this->extractMarketTokens($market);
        if ($tokens === [] || ! $this->marketMatchesCondition($market, $conditionId, $tokens)) {
            return $snapshot;
        }

        $winningOutcome = null;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $winner = $token['winner'] ?? false;
                // winner 可能是 true, 1, "1", "true" 等
                if ($winner === true || $winner === 1 || $winner === '1' || $winner === 'true') {
                    $winningOutcome = $this->normalizeOutcome((string) ($token['outcome'] ?? ''));
                    break;
                }
            }
        }

        if ($winningOutcome === null) {
            return $snapshot;
        }

        $marketEndAt = $this->orderMarketEndAt($order, $market);
        if ($marketEndAt !== null && now()->lt($marketEndAt)) {
            return $snapshot;
        }

        $filledSize = $snapshot['filled_size'] ?? null;
        $orderPrice = $snapshot['order_price'] ?? null;
        $outcome = $this->normalizeOutcome((string) ($snapshot['outcome'] ?? ''));
        $positionNotionalUsdc = $snapshot['position_notional_usdc'] ?? null;
        $isWin = $winningOutcome === $outcome;
        $pnlUsdc = $this->calculateSettledPnlUsdc($filledSize, $orderPrice, $isWin);
        $profitUsdc = max(0, (int) $pnlUsdc);
        $roiBps = null;
        if ($positionNotionalUsdc !== null && (int) $positionNotionalUsdc > 0) {
            $roiBps = (int) BigDecimal::of((string) $pnlUsdc)
                ->dividedBy((string) $positionNotionalUsdc, 8, RoundingMode::HALF_UP)
                ->multipliedBy('10000')
                ->toScale(0, RoundingMode::HALF_UP)
                ->__toString();
        }

        $snapshot['is_settled'] = true;
        $snapshot['settled_at'] = $snapshot['settled_at'] ?? now();
        $snapshot['winning_outcome'] = $winningOutcome;
        $snapshot['settlement_source'] = 'claim_receipt_recovered';
        $snapshot['pnl_usdc'] = $pnlUsdc;
        $snapshot['profit_usdc'] = $profitUsdc;
        $snapshot['roi_bps'] = $roiBps;
        $snapshot['is_win'] = $isWin;
        $snapshot['claimable_usdc'] = $profitUsdc > 0 ? $profitUsdc : 0;
        $snapshot['settlement_payload'] = [
            'condition_id' => $conditionId,
            'market' => $market,
            'market_tokens' => $tokens,
            'remote_order' => $remote,
            'recovered_from' => 'confirmed_claim_receipt',
        ];

        return $snapshot;
    }

    /**
     * @param array<int,mixed> $candidates
     */
    private function pickDecimal(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^\d+(\.\d+)?$/', $candidate)) {
                return $candidate;
            }
            if (is_int($candidate) || is_float($candidate)) {
                $value = (string) $candidate;
                if (preg_match('/^\d+(\.\d+)?$/', $value)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $remote
     */
    private function resolveConditionId(PmOrder $order, array $remote = []): ?string
    {
        $intent = $order->intent;
        $task = $intent?->copyTask;
        $riskSnapshot = is_array($intent?->risk_snapshot) ? $intent->risk_snapshot : [];
        $request = is_array($order->request_payload) ? $order->request_payload : [];

        $candidates = [
            $remote['market'] ?? null,
            $order->settlement_payload['condition_id'] ?? null,
            $order->response_payload['market'] ?? null,
            $request['condition_id'] ?? null,
            $request['request']['input']['condition_id'] ?? null,
            $task?->market_id,
            $riskSnapshot['market_id'] ?? null,
            $request['market_id'] ?? null,
            $request['request']['input']['market_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^0x[a-fA-F0-9]{64}$/', $candidate) === 1) {
                return strtolower($candidate);
            }
        }

        $numericMarketId = null;
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^\d+$/', $candidate) === 1) {
                $numericMarketId = $candidate;
                break;
            }
        }

        if ($numericMarketId === null) {
            return null;
        }

        try {
            $market = $this->gammaMarketById($numericMarketId);
            $conditionId = $market['conditionId'] ?? $market['condition_id'] ?? null;
            if (is_string($conditionId) && preg_match('/^0x[a-fA-F0-9]{64}$/', $conditionId) === 1) {
                return strtolower($conditionId);
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchResolvedMarket(string $conditionId, PmOrder $order): array
    {
        // 优先使用已有的 settlement_payload 中的市场数据（避免重复API调用）
        if (is_array($order->settlement_payload) && isset($order->settlement_payload['market'])) {
            $cachedMarket = $order->settlement_payload['market'];
            if (is_array($cachedMarket) && $cachedMarket !== [] && $this->marketMatchesCondition($cachedMarket, $conditionId)) {
                return $cachedMarket;
            }
        }

        try {
            $market = $this->factory->makeReadClient()->clob()->markets()->get($conditionId);
            if (is_array($market) && $market !== [] && $this->marketMatchesCondition($market, $conditionId)) {
                return $market;
            }
        } catch (Throwable) {
        }

        $gammaCandidates = [];
        $taskMarketId = $order->intent?->copyTask?->market_id;
        $riskMarketId = is_array($order->intent?->risk_snapshot) ? ($order->intent->risk_snapshot['market_id'] ?? null) : null;
        $requestMarketId = is_array($order->request_payload) ? ($order->request_payload['market_id'] ?? ($order->request_payload['request']['input']['market_id'] ?? null)) : null;

        // 优先使用下单时的快照数据（riskMarketId, requestMarketId），最后才使用 taskMarketId
        // 因为 taskMarketId 会动态更新为任务当前监听的最新市场，而不是下单时的市场
        foreach ([$riskMarketId, $requestMarketId, $taskMarketId] as $candidate) {
            if (is_string($candidate) && preg_match('/^\d+$/', $candidate) === 1) {
                $gammaCandidates[] = $candidate;
            }
        }

        foreach (array_values(array_unique($gammaCandidates)) as $gammaMarketId) {
            try {
                $market = $this->gammaMarketById($gammaMarketId);
                if ($market !== []) {
                    return $market;
                }
            } catch (Throwable) {
            }
        }

        return [];
    }

    /**
     * @return array<int,mixed>
     */
    private function extractMarketTokens(array $market): array
    {
        $tokens = $market['tokens'] ?? null;
        if (is_array($tokens) && $tokens !== []) {
            return array_values($tokens);
        }

        $outcomes = $this->decodeJsonArray($market['outcomes'] ?? []);
        $prices = $this->decodeJsonArray($market['outcomePrices'] ?? []);
        $winner = strtolower(trim((string) ($market['winner'] ?? $market['result'] ?? '')));
        if ($outcomes === []) {
            return [];
        }

        $normalizedWinner = $this->normalizeOutcome($winner);
        $items = [];
        foreach (array_values($outcomes) as $index => $outcome) {
            $outcomeText = (string) $outcome;
            $price = isset($prices[$index]) ? (string) $prices[$index] : null;
            $isWinnerByPrice = is_string($price)
                && preg_match('/^\d+(\.\d+)?$/', $price) === 1
                && bccomp($price, '1', 6) === 0;

            $items[] = [
                'outcome' => $outcomeText,
                'winner' => $normalizedWinner !== ''
                    ? $this->normalizeOutcome($outcomeText) === $normalizedWinner
                    : $isWinnerByPrice,
                'price' => $price,
            ];
        }

        return $items;
    }

    /**
     * @return array<int,mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function gammaMarketById(string $marketId): array
    {
        $baseUrl = rtrim((string) config('pm.gamma_base_url', 'https://gamma-api.polymarket.com'), '/');
        $json = json_decode((string) file_get_contents($baseUrl . '/markets?id=' . urlencode($marketId)), true);
        if (!is_array($json) || $json === []) {
            return [];
        }

        $market = $json[0] ?? null;
        return is_array($market) ? $market : [];
    }

    private function normalizeOutcome(string $value): string
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'yes' => 'yes',
            'no' => 'no',
            'up' => 'up',
            'down' => 'down',
            default => $value,
        };
    }

    private function multiplyDecimal(string $left, string $right): string
    {
        return BigDecimal::of($left)
            ->multipliedBy($right)
            ->toScale(6, RoundingMode::DOWN)
            ->__toString();
    }

    private function decimalToUsdc(string $value): int
    {
        return (int) BigDecimal::of($value)
            ->multipliedBy('1000000')
            ->toScale(0, RoundingMode::DOWN)
            ->__toString();
    }

    private function calculateSettledPnlUsdc(?string $filledSize, ?string $orderPrice, bool $isWin): ?int
    {
        if ($filledSize === null || $orderPrice === null) {
            return null;
        }

        $cost = BigDecimal::of($filledSize)
            ->multipliedBy($orderPrice)
            ->toScale(6, RoundingMode::DOWN);

        $pnl = $isWin
            ? BigDecimal::of($filledSize)->minus($cost)
            : $cost->multipliedBy('-1');

        return (int) $pnl
            ->multipliedBy('1000000')
            ->toScale(0, RoundingMode::DOWN)
            ->__toString();
    }
}
