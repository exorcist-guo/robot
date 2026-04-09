<?php

namespace App\Admin\Controllers;

use App\Models\Member;
use App\Models\PerformanceRecord;
use App\Models\Pm\PmOrder;
use App\Models\Pm\PmOrderIntent;
use App\Http\Controllers\Controller;
use App\Services\BnbService;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Layout\Row;
use Dcat\Admin\Layout\Column;
use Dcat\Admin\Widgets\Table;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
    public function index(Content $content)
    {
        // 获取统计数据
        $totalMembers = Member::count();
        $newMembersToday = Member::whereDate('created_at', today())->count();
        $totalParticipations = PerformanceRecord::count();
        $totalAmount = PerformanceRecord::sum('amount');

        // 订单统计（不包含已取消的订单）
        $totalOrders = PmOrder::whereHas('intent', function ($q) {
            $q->where('status', '!=', 'cancelled');
        })->where('is_settled', true)->count();

        $profitOrders = PmOrder::whereHas('intent', function ($q) {
            $q->where('status', '!=', 'cancelled');
        })->where('is_settled', true)->where('pnl_usdc', '>', 0)->count();

        $lossOrders = PmOrder::whereHas('intent', function ($q) {
            $q->where('status', '!=', 'cancelled');
        })->where('is_settled', true)->where('pnl_usdc', '<', 0)->count();

        // 今日订单统计
        $todayOrders = PmOrder::whereHas('intent', function ($q) {
            $q->where('status', '!=', 'cancelled');
        })->where('is_settled', true)->whereDate('created_at', today())->count();

        $todayProfitOrders = PmOrder::whereHas('intent', function ($q) {
            $q->where('status', '!=', 'cancelled');
        })->where('is_settled', true)->where('pnl_usdc', '>', 0)->whereDate('created_at', today())->count();

        $todayLossOrders = PmOrder::whereHas('intent', function ($q) {
            $q->where('status', '!=', 'cancelled');
        })->where('is_settled', true)->where('pnl_usdc', '<', 0)->whereDate('created_at', today())->count();

        // 今日胜率
        $todayWinRate = $todayOrders > 0 ? round(($todayProfitOrders / $todayOrders) * 100, 2) : 0;

        // 按触发条件统计
        $statsByTrigger = $this->getStatsByTrigger();

        return $content
            ->title('数据统计')
            ->description('实时数据概览')
            ->row(function (Row $row) use ($totalMembers, $newMembersToday, $totalParticipations, $totalAmount) {
                $row->column(3, $this->renderCard('总用户数', $totalMembers, 'feather icon-users', 'primary'));
                $row->column(3, $this->renderCard('今日新增', $newMembersToday, 'feather icon-user-plus', 'success'));
                $row->column(3, $this->renderCard('总参与数', $totalParticipations, 'feather icon-activity', 'info'));
                $row->column(3, $this->renderCard('总参与金额', number_format($totalAmount, 2) . ' USDT', 'feather icon-dollar-sign', 'warning'));
            })
            ->row(function (Row $row) use ($totalOrders, $profitOrders, $lossOrders, $todayOrders, $todayProfitOrders, $todayLossOrders, $todayWinRate) {
                $row->column(3, $this->renderCard('总订单数', $totalOrders, 'feather icon-file-text', 'primary'));
                $row->column(3, $this->renderCard('盈利订单', $profitOrders, 'feather icon-trending-up', 'success'));
                $row->column(3, $this->renderCard('亏损订单', $lossOrders, 'feather icon-trending-down', 'danger'));
                $row->column(3, $this->renderCard('今日订单', $todayOrders, 'feather icon-calendar', 'info'));
            })
            ->row(function (Row $row) use ($todayProfitOrders, $todayLossOrders, $todayWinRate) {
                $row->column(3, $this->renderCard('今日盈利', $todayProfitOrders, 'feather icon-arrow-up', 'success'));
                $row->column(3, $this->renderCard('今日亏损', $todayLossOrders, 'feather icon-arrow-down', 'danger'));
                $row->column(3, $this->renderCard('今日胜率', $todayWinRate . '%', 'feather icon-percent', 'warning'));
            })
            ->row(function (Row $row) use ($statsByTrigger) {
                $row->column(12, $this->renderTriggerStatsTable($statsByTrigger));
            });
    }

    private function getStatsByTrigger()
    {
        // 获取所有已结算的订单（不包含已取消）
        $orders = PmOrder::with('intent')
            ->whereHas('intent', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->where('is_settled', true)
            ->get();

        // 按触发条件分组统计
        $stats = [];
        foreach ($orders as $order) {
            $priceTimeLimit = $order->intent?->price_time_limit ?? '未知';

            if (!isset($stats[$priceTimeLimit])) {
                $stats[$priceTimeLimit] = [
                    'trigger_condition' => $priceTimeLimit,
                    'total_orders' => 0,
                    'profit_orders' => 0,
                    'loss_orders' => 0,
                    'total_pnl_usdc' => 0,
                    'win_rate' => 0,
                ];
            }

            $stats[$priceTimeLimit]['total_orders']++;

            $pnlUsdc = $order->pnl_usdc !== null ? (int) $order->pnl_usdc : 0;
            $stats[$priceTimeLimit]['total_pnl_usdc'] += $pnlUsdc;

            if ($pnlUsdc > 0) {
                $stats[$priceTimeLimit]['profit_orders']++;
            } elseif ($pnlUsdc < 0) {
                $stats[$priceTimeLimit]['loss_orders']++;
            }
        }

        // 计算胜率
        foreach ($stats as $key => $stat) {
            if ($stat['total_orders'] > 0) {
                $stats[$key]['win_rate'] = round(($stat['profit_orders'] / $stat['total_orders']) * 100, 2);
            }
        }

        // 转换为数组并按总订单数降序排序
        $result = array_values($stats);
        usort($result, fn ($a, $b) => $b['total_orders'] <=> $a['total_orders']);

        return $result;
    }

    private function renderTriggerStatsTable($stats)
    {
        $headers = ['触发条件', '总订单数', '盈利订单', '亏损订单', '盈亏金额', '胜率'];
        $rows = [];

        foreach ($stats as $stat) {
            $winRateColor = $stat['win_rate'] >= 60 ? 'success' : ($stat['win_rate'] < 40 ? 'danger' : 'warning');
            $pnlUsdc = $stat['total_pnl_usdc'] / 1000000;
            $pnlColor = $pnlUsdc > 0 ? 'success' : ($pnlUsdc < 0 ? 'danger' : 'secondary');
            $pnlText = $pnlUsdc > 0 ? '+' . number_format($pnlUsdc, 2) : number_format($pnlUsdc, 2);

            $rows[] = [
                $stat['trigger_condition'],
                $stat['total_orders'],
                '<span class="text-success">' . $stat['profit_orders'] . '</span>',
                '<span class="text-danger">' . $stat['loss_orders'] . '</span>',
                '<span class="text-' . $pnlColor . ' font-weight-bold">$' . $pnlText . '</span>',
                '<span class="text-' . $winRateColor . ' font-weight-bold">' . $stat['win_rate'] . '%</span>',
            ];
        }

        $table = new Table($headers, $rows);

        return <<<HTML
<div class="card">
    <div class="card-header">
        <h4 class="card-title">触发条件统计</h4>
    </div>
    <div class="card-body">
        {$table->render()}
    </div>
</div>
HTML;
    }

    private function renderCard($title, $content, $icon, $color = 'primary')
    {
        $colors = [
            'primary' => 'primary',
            'success' => 'success',
            'info' => 'info',
            'warning' => 'warning',
            'danger' => 'danger',
        ];
        $bgColor = $colors[$color] ?? 'primary';

        return <<<HTML
<div class="card bg-{$bgColor}">
    <div class="card-body">
        <div class="d-flex">
            <div class="text-white">
                <h3 class="mb-0">{$content}</h3>
                <span class="small">{$title}</span>
            </div>
            <div class="ml-auto">
                <i class="{$icon} f-24 text-white-50"></i>
            </div>
        </div>
    </div>
</div>
HTML;
    }
}
