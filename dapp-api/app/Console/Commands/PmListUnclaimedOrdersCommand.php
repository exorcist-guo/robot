<?php

namespace App\Console\Commands;

use App\Models\Pm\PmOrder;
use Illuminate\Console\Command;

class PmListUnclaimedOrdersCommand extends Command
{
    protected $signature = 'pm:list-unclaimed-orders
        {--member-id= : 指定 member_id}
        {--min-amount= : 最小金额（USDC）}';

    protected $description = '列出所有未兑换的订单';

    public function handle(): int
    {
        $this->info('=== 查询未兑换订单 ===');
        $this->newLine();

        // 构建查询
        $query = PmOrder::where('is_settled', true)
            ->where('pnl_usdc', '>', 0)
            ->whereIn('claim_status', [0, 1]); // NONE 或 PENDING

        // 按 member_id 筛选
        if ($memberId = (int) $this->option('member-id')) {
            $query->whereHas('intent', fn ($q) => $q->where('member_id', $memberId));
            $this->info("筛选条件: member_id = {$memberId}");
        }

        // 按最小金额筛选
        if ($minAmount = $this->option('min-amount')) {
            $minUsdc = (float) $minAmount * 1000000;
            $query->where('pnl_usdc', '>=', $minUsdc);
            $this->info("筛选条件: 最小金额 >= \${$minAmount}");
        }

        $orders = $query->orderBy('id', 'asc')->get();

        if ($orders->isEmpty()) {
            $this->info('✅ 没有未兑换的订单');
            return self::SUCCESS;
        }

        $this->info("找到 {$orders->count()} 个未兑换订单");
        $this->newLine();

        // 显示订单列表
        $totalPnl = 0;
        $statusMap = [
            0 => 'NONE',
            1 => 'PENDING',
            2 => 'CLAIMING',
            3 => 'CLAIMED',
            4 => 'CONFIRMED',
        ];

        $tableData = [];
        foreach ($orders as $order) {
            $pnl = $order->pnl_usdc / 1000000;
            $totalPnl += $pnl;

            $tableData[] = [
                'ID' => $order->id,
                'Member' => $order->intent?->member_id ?? '-',
                'Outcome' => $order->outcome,
                'Winner' => $order->winning_outcome,
                'PNL (USDC)' => '$' . number_format($pnl, 2),
                'Claimable' => '$' . number_format(($order->claimable_usdc ?? 0) / 1000000, 2),
                'Status' => $statusMap[$order->claim_status] ?? 'UNKNOWN',
                'Has ConditionId' => $this->hasConditionId($order) ? '✓' : '✗',
            ];
        }

        $this->table(
            ['ID', 'Member', 'Outcome', 'Winner', 'PNL (USDC)', 'Claimable', 'Status', 'Has ConditionId'],
            $tableData
        );

        $this->newLine();
        $this->info("总计未兑换金额: \$" . number_format($totalPnl, 2));
        $this->newLine();

        // 检查是否有缺少 conditionId 的订单
        $missingConditionId = $orders->filter(fn ($order) => !$this->hasConditionId($order));
        if ($missingConditionId->isNotEmpty()) {
            $this->warn("⚠️  {$missingConditionId->count()} 个订单缺少 conditionId，可能无法兑奖");
            $this->warn("   订单ID: " . $missingConditionId->pluck('id')->implode(', '));
            $this->newLine();
        }

        // 提示如何批量兑奖
        $this->info('💡 批量兑奖命令:');
        $this->line('   php artisan pm:sync-order-settlement --only-unsettled --queue-claim');
        $this->newLine();

        return self::SUCCESS;
    }

    private function hasConditionId(PmOrder $order): bool
    {
        $payload = $order->settlement_payload;
        if (!$payload) {
            return false;
        }

        $candidates = [
            $payload['condition_id'] ?? null,
            $payload['market']['conditionId'] ?? null,
            $payload['market']['condition_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^0x[a-fA-F0-9]{64}$/', $candidate) === 1) {
                return true;
            }
        }

        return false;
    }
}
