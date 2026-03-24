<?php

namespace App\Jobs;

use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmOrder;
use App\Services\Pm\CustodyCipher;
use App\Services\Pm\PolymarketClientFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PmSyncOrderStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $orderId)
    {
    }

    public function handle(PolymarketClientFactory $factory, CustodyCipher $cipher): void
    {
        $order = PmOrder::with('intent.copyTask.member.custodyWallet.apiCredentials')->find($this->orderId);
        if (!$order || !$order->poly_order_id) {
            return;
        }

        $wallet = $order->intent?->member?->custodyWallet;
        $credRecord = $wallet?->apiCredentials;
        if (!$wallet || !$credRecord) {
            return;
        }

        $trading = app(\App\Services\Pm\PolymarketTradingService::class);
        $creds = $trading->decodeApiCredentials($credRecord);
        $privateKey = $cipher->decryptString($wallet->private_key_ciphertext);
        $client = $factory->makeAuthedClobClient($privateKey, $creds);
        $remote = $client->clob()->orders()->get((string) $order->poly_order_id);

        $remoteStatus = strtolower((string) ($remote['status'] ?? ''));
        $localStatus = match ($remoteStatus) {
            'matched', 'filled' => PmOrder::STATUS_FILLED,
            'partially_matched', 'partial', 'partially_filled' => PmOrder::STATUS_PARTIAL,
            'canceled', 'cancelled' => PmOrder::STATUS_CANCELED,
            'rejected' => PmOrder::STATUS_REJECTED,
            default => PmOrder::STATUS_SUBMITTED,
        };

        $candidate = (string) ($remote['takingAmount'] ?? $remote['filledAmount'] ?? '0');
        if (!preg_match('/^\d+(\.\d+)?$/', $candidate)) {
            $candidate = '0';
        }

        $filledUsdc = (int) BigDecimal::of($candidate)
            ->multipliedBy('1000000')
            ->toScale(0, RoundingMode::DOWN)
            ->__toString();

        $order->status = $localStatus;
        $order->response_payload = $remote;
        $order->filled_usdc = max((int) $order->filled_usdc, $filledUsdc);
        $order->avg_price = $order->avg_price ?: ($order->request_payload['normalized_price'] ?? null);
        $order->last_sync_at = now();
        $order->save();

        $this->syncTailSweepOutcome($order, $factory);
    }

    private function syncTailSweepOutcome(PmOrder $order, PolymarketClientFactory $factory): void
    {
        $intent = $order->intent;
        $task = $intent?->copyTask;
        $riskSnapshot = is_array($intent?->risk_snapshot) ? $intent->risk_snapshot : [];
        if (!$intent || !$task || $task->mode !== PmCopyTask::MODE_TAIL_SWEEP) {
            return;
        }

        if (!in_array($order->status, [PmOrder::STATUS_FILLED, PmOrder::STATUS_PARTIAL], true)) {
            return;
        }

        $marketId = (string) ($riskSnapshot['market_id'] ?? '');
        $triggerSide = (string) ($riskSnapshot['trigger_side'] ?? '');
        $marketEndAt = $task->market_end_at;
        if ($marketId === '' || !in_array($triggerSide, ['up', 'down'], true) || !$marketEndAt || now()->lt($marketEndAt)) {
            return;
        }

        $market = $factory->makeReadClient()->clob()->markets()->get($marketId);
        if (!is_array($market)) {
            return;
        }

        $tokens = $market['tokens'] ?? [];
        if (!is_array($tokens) || $tokens === []) {
            return;
        }

        $winningOutcome = null;
        foreach ($tokens as $token) {
            if (is_array($token) && ($token['winner'] ?? false) === true) {
                $winningOutcome = strtolower((string) ($token['outcome'] ?? ''));
                break;
            }
        }

        if (!in_array($winningOutcome, ['up', 'down'], true)) {
            return;
        }

        $requestPayload = is_array($order->request_payload) ? $order->request_payload : [];
        if (($requestPayload['tail_result_synced'] ?? false) === true) {
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
}
