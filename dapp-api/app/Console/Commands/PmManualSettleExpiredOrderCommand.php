<?php

namespace App\Console\Commands;

use App\Models\Pm\PmOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PmManualSettleExpiredOrderCommand extends Command
{
    protected $signature = 'pm:manual-settle-expired
                            {--order-id= : 订单ID}
                            {--winning-outcome= : 获胜方向 (up/down)}
                            {--dry-run : 只显示结果，不保存}';

    protected $description = '手动结算已过期的订单（Polymarket API 已移除的市场）';

    public function handle(): int
    {
        $orderId = $this->option('order-id');
        $winningOutcome = strtolower(trim((string) $this->option('winning-outcome')));
        $dryRun = $this->option('dry-run');

        if (!$orderId) {
            $this->error('必须指定 --order-id');
            return 1;
        }

        if (!in_array($winningOutcome, ['up', 'down'], true)) {
            $this->error('必须指定 --winning-outcome=up 或 --winning-outcome=down');
            return 1;
        }

        $order = PmOrder::find($orderId);
        if (!$order) {
            $this->error("订单 {$orderId} 不存在");
            return 1;
        }

        if ($order->is_settled) {
            $this->warn("订单 {$orderId} 已经结算过了");
            return 0;
        }

        $this->info("订单 #{$order->id}");
        $this->table(
            ['字段', '值'],
            [
                ['方向', $order->outcome],
                ['数量', $order->filled_size],
                ['价格', $order->order_price],
                ['成交额', sprintf('%.4f USDC', ($order->filled_usdc ?? 0) / 1000000)],
            ]
        );

        $this->newLine();
        $this->info("手动结算结果: {$winningOutcome}");

        $isWin = $order->outcome === $winningOutcome;
        $filledSize = $order->filled_size;
        $orderPrice = $order->order_price;

        if (!$filledSize || !$orderPrice) {
            $this->error('订单缺少 filled_size 或 order_price');
            return 1;
        }

        // 计算盈亏
        $positionNotional = bcmul($filledSize, $orderPrice, 6);
        $pnlUsdc = $isWin
            ? bcmul(bcsub('1', $orderPrice, 6), $filledSize, 6)
            : bcmul('-' . $orderPrice, $filledSize, 6);
        $pnlUsdc = (int) bcmul($pnlUsdc, '1000000', 0);
        $profitUsdc = max(0, $pnlUsdc);

        $this->table(
            ['字段', '值'],
            [
                ['是否盈利', $isWin ? '✓ 盈利' : '✗ 亏损'],
                ['盈亏(PNL)', sprintf('%+.4f USDC', $pnlUsdc / 1000000)],
                ['盈利金额', sprintf('%.4f USDC', $profitUsdc / 1000000)],
            ]
        );

        if ($dryRun) {
            $this->warn('--dry-run 模式，不保存数据');
            return 0;
        }

        $order->is_settled = true;
        $order->settled_at = now();
        $order->winning_outcome = $winningOutcome;
        $order->settlement_source = 'manual_expired_market';
        $order->is_win = $isWin;
        $order->pnl_usdc = $pnlUsdc;
        $order->profit_usdc = $profitUsdc;
        $order->claimable_usdc = $profitUsdc;
        $order->claim_status = $profitUsdc > 0 ? PmOrder::CLAIM_STATUS_PENDING : PmOrder::CLAIM_STATUS_NOT_NEEDED;
        $order->save();

        $this->info('✓ 订单已手动结算');

        if ($profitUsdc > 0) {
            $this->info('💰 订单有盈利，可以尝试兑奖（但可能因市场过期而失败）');
        }

        return 0;
    }
}
