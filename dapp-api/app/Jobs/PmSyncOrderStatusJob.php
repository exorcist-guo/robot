<?php

namespace App\Jobs;

use App\Jobs\PmSyncOrderSettlementJob;
use App\Models\Pm\PmOrder;
use App\Services\Pm\PmOrderSettlementSyncService;
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

    public function handle(PmOrderSettlementSyncService $service): void
    {
        $order = PmOrder::with('intent.copyTask.member.custodyWallet.apiCredentials')->find($this->orderId);
        if (!$order || !$order->poly_order_id) {
            return;
        }

        $result = $service->sync($order);
        $snapshot = is_array($result['snapshot'] ?? null) ? $result['snapshot'] : [];
        $localStatus = (int) ($snapshot['local_status'] ?? $order->status);

        if (in_array($localStatus, [PmOrder::STATUS_FILLED, PmOrder::STATUS_PARTIAL], true)) {
            PmSyncOrderSettlementJob::dispatch($order->id, true);
        }
    }
}
