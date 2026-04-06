<?php

namespace App\Console\Commands;

use App\Jobs\PmSyncOrderSettlementJob;
use App\Models\Pm\PmOrder;
use Illuminate\Console\Command;

class PmSyncOrdersCommand extends Command
{
    protected $signature = 'pm:sync-orders {--once : 仅同步一次}';

    protected $description = '同步 Polymarket 订单状态到本地 pm_orders';

    public function handle(): int
    {
        $orders = PmOrder::whereNotNull('poly_order_id')
            ->whereIn('status', [PmOrder::STATUS_SUBMITTED, PmOrder::STATUS_PARTIAL])
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($orders as $order) {
            PmSyncOrderSettlementJob::dispatchSync($order->id);
            $this->info("已同步订单 {$order->id} / {$order->poly_order_id}");
        }

        if ($orders->isEmpty()) {
            $this->info('没有需要同步的订单');
        }

        return self::SUCCESS;
    }
}
