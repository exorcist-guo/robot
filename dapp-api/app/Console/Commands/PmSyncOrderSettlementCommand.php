<?php

namespace App\Console\Commands;

use App\Models\Pm\PmOrder;
use App\Services\Pm\PmOrderSettlementSyncService;
use Illuminate\Console\Command;

class PmSyncOrderSettlementCommand extends Command
{
    protected $signature = 'pm:sync-order-settlement
        {--order-id= : 指定本地订单 ID}
        {--poly-order-id= : 指定 Poly Order ID}
        {--member-id= : 指定 member_id}
        {--only-unsettled : 仅同步未结算订单}
        {--queue-claim : 结算后将盈利订单放入自动提取收益队列}
        {--dry-run : 只输出结果，不落库}';

    protected $description = '同步 Polymarket 订单结算信息并补充收益字段';

    public function handle(PmOrderSettlementSyncService $service): int
    {
        $query = PmOrder::query()
            ->with('intent.copyTask.member.custodyWallet.apiCredentials')
            ->whereNotNull('poly_order_id')
            ->orderBy('id');

        if ($orderId = (int) $this->option('order-id')) {
            $query->where('id', $orderId);
        }

        if ($polyOrderId = trim((string) $this->option('poly-order-id'))) {
            $query->where('poly_order_id', $polyOrderId);
        }

        if ($memberId = (int) $this->option('member-id')) {
            $query->whereHas('intent', fn ($q) => $q->where('member_id', $memberId));
        }

        if ((bool) $this->option('only-unsettled')) {
            $query->where(function ($q) {
                $q->whereNull('is_settled')
                    ->orWhere('is_settled', false)
                    ->orWhere(function ($claimQuery) {
                        $claimQuery->where('claim_status', PmOrder::CLAIM_STATUS_CLAIMING)
                            ->whereNull('claim_completed_at');
                    });
            });
        }

        $orders = $query->limit(200)->get();
        if ($orders->isEmpty()) {
            $this->info('没有需要同步的订单');
            return self::SUCCESS;
        }

        $queueClaim = (bool) $this->option('queue-claim');
        $dryRun = (bool) $this->option('dry-run');

        foreach ($orders as $order) {
            try {
                $result = $service->sync($order, $queueClaim, $dryRun);
                if ($dryRun) {
                    $this->line(json_encode([
                        'order_id' => $order->id,
                        'poly_order_id' => $order->poly_order_id,
                        'result' => $result,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    continue;
                }

                $this->info("已同步订单 {$order->id} / {$order->poly_order_id}");
            } catch (\Throwable $e) {
                $this->error("同步订单 {$order->id} 失败: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
