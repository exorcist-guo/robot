<?php

namespace App\Console\Commands;

use App\Models\Pm\PmOrder;
use Illuminate\Console\Command;

class PmLossOrderAvgPriceCommand extends Command
{
    protected $signature = 'pm:loss-order-avg-price {--member_id= : 仅统计指定 member_id} {--limit=0 : 限制输出条数，0 表示全部}';

    protected $description = '打印所有亏损订单的平均成交价';

    public function handle(): int
    {
        $query = PmOrder::query()
            ->with('intent:id,member_id,price_time_limit')
            ->where('is_settled', true)
            ->whereNotNull('pnl_usdc')
            ->where('pnl_usdc', '<', 0)
            ->whereNotNull('avg_price')
            ->orderByDesc('id');

        $memberId = $this->option('member_id');
        if ($memberId !== null && $memberId !== '') {
            $query->whereHas('intent', fn ($q) => $q->where('member_id', (int) $memberId));
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $orders = $query->get();

        if ($orders->isEmpty()) {
            $this->warn('未找到符合条件的亏损订单');
            return self::SUCCESS;
        }

        $rows = [];
        $priceSum = '0';
        $priceCount = 0;

        foreach ($orders as $order) {
            $avgPrice = (string) $order->avg_price;
            $priceSum = bcadd($priceSum, $avgPrice, 8);
            $priceCount++;

            $rows[] = [
                $order->id,
                $order->intent?->member_id ?? '-',
                $order->intent?->price_time_limit ?? '-',
                $avgPrice,
                number_format(((int) $order->pnl_usdc) / 1000000, 2),
                $order->submitted_at?->toDateTimeString() ?? '-',
            ];
        }

        $average = $priceCount > 0 ? bcdiv($priceSum, (string) $priceCount, 8) : '0';

        $this->table(
            ['订单ID', 'Member ID', '触发条件', '平均成交价', '盈亏(USDC)', '下单时间'],
            $rows
        );

        $this->newLine();
        $this->info('亏损订单数量: '.$priceCount);
        $this->info('亏损订单平均成交价: '.$average);

        return self::SUCCESS;
    }
}
