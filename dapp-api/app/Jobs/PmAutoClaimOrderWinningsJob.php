<?php

namespace App\Jobs;

use App\Models\Pm\PmOrder;
use App\Services\Pm\PolymarketClaimService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PmAutoClaimOrderWinningsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $orderId)
    {
    }

    public function handle(PolymarketClaimService $claimService): void
    {
        $order = PmOrder::with('intent.copyTask.member.custodyWallet.apiCredentials')->find($this->orderId);
        if (!$order) {
            return;
        }

        $plan = $claimService->buildClaimPlan($order);
        $order->claim_payload = $plan;
        $order->claim_last_checked_at = now();
        $order->save();

        if (($plan['ready'] ?? false) !== true) {
            $order->claim_status = PmOrder::CLAIM_STATUS_FAILED;
            $order->claim_last_error = '兑奖参数不完整';
            $order->save();
            return;
        }

        $updated = PmOrder::query()
            ->where('id', $order->id)
            ->whereIn('claim_status', [PmOrder::CLAIM_STATUS_PENDING, PmOrder::CLAIM_STATUS_FAILED])
            ->update([
                'claim_status' => PmOrder::CLAIM_STATUS_CLAIMING,
                'claim_attempts' => (int) $order->claim_attempts + 1,
                'claim_requested_at' => now(),
                'claim_last_checked_at' => now(),
                'claim_payload' => $plan,
            ]);

        if ($updated === 0) {
            return;
        }

        $order->refresh();

        try {
            $result = $claimService->claimOrderWinnings($order);
            $order->claim_payload = $result;
            $order->claim_tx_hash = (string) ($result['tx_hash'] ?? $order->claim_tx_hash);
            $order->claim_status = ($result['submitted'] ?? false) || ($result['already_claimed'] ?? false)
                ? PmOrder::CLAIM_STATUS_CLAIMED
                : PmOrder::CLAIM_STATUS_SKIPPED;
            $order->claim_completed_at = now();
            $order->claim_last_error = null;
            $order->save();
        } catch (\Throwable $e) {
            $order->claim_status = PmOrder::CLAIM_STATUS_FAILED;
            $order->claim_last_error = $e->getMessage();
            $order->claim_payload = array_merge(is_array($order->claim_payload) ? $order->claim_payload : [], [
                'error' => $e->getMessage(),
            ]);
            $order->save();

            throw $e;
        }
    }
}
