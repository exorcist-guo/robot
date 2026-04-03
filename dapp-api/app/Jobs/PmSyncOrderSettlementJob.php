<?php

namespace App\Jobs;

use App\Models\Pm\PmOrder;
use App\Services\Pm\PmOrderSettlementSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PmSyncOrderSettlementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $orderId,
        private readonly bool $queueClaim = false,
        private readonly bool $dryRun = false,
    ) {
    }

    public function handle(PmOrderSettlementSyncService $service): void
    {
        $order = PmOrder::with('intent.copyTask.member.custodyWallet.apiCredentials')->find($this->orderId);
        if (!$order || !$order->poly_order_id) {
            return;
        }

        $service->sync($order, $this->queueClaim, $this->dryRun);
    }
}
