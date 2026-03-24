<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pm\PmMember;
use App\Models\Pm\PmOrder;
use App\Models\Pm\PmPortfolioSnapshot;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    use ApiResponseTrait;

    private function currentMember(Request $request): PmMember
    {
        /** @var PmMember $user */
        $user = $request->user();
        return $user;
    }

    public function index(Request $request)
    {
        $member = $this->currentMember($request);
        $snapshot = PmPortfolioSnapshot::where('member_id', $member->id)
            ->orderByDesc('as_of')
            ->first();

        $recentOrders = PmOrder::with(['intent.leaderTrade.leader'])
            ->whereIn('status', [1, 2, 3])
            ->where(function ($query) {
                $query->whereNull('error_message')
                    ->orWhere('error_message', '');
            })
            ->whereHas('intent', fn ($q) => $q->where('member_id', $member->id))
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(function (PmOrder $order) {
                $intent = $order->intent;
                $trade = $intent?->leaderTrade;
                $leader = $trade?->leader;
                $statusText = match ((int) $order->status) {
                    1 => '已提交',
                    2 => '已成交',
                    3 => '部分成交',
                    4 => '已取消',
                    5 => '已拒绝',
                    6 => '异常',
                    default => '未知状态',
                };

                return [
                    'order_id' => $order->id,
                    'poly_order_id' => $order->poly_order_id,
                    'status' => $order->status,
                    'status_text' => $statusText,
                    'failure_category' => $order->failure_category,
                    'is_retryable' => (bool) ($order->is_retryable ?? false),
                    'retry_count' => (int) ($order->retry_count ?? 0),
                    'error_code' => $order->error_code,
                    'error_message' => $order->error_message,
                    'filled_usdc' => (string) $order->filled_usdc,
                    'avg_price' => $order->avg_price,
                    'submitted_at' => $order->submitted_at?->toDateTimeString(),
                    'leader' => $leader ? [
                        'id' => $leader->id,
                        'display_name' => $leader->display_name,
                        'proxy_wallet' => $leader->proxy_wallet,
                    ] : null,
                    'trade' => $trade ? [
                        'side' => $trade->side,
                        'price' => $trade->price,
                        'size_usdc' => (string) $trade->size_usdc,
                        'traded_at' => $trade->traded_at?->toDateTimeString(),
                    ] : null,
                ];
            });

        return $this->success('ok', [
            'address' => $member->address,
            'nickname' => $member->nickname,
            'avatar_url' => $member->avatar_url,
            'available_usdc' => $snapshot?->available_usdc !== null ? (string) $snapshot->available_usdc : '0',
            'equity_usdc' => $snapshot?->equity_usdc !== null ? (string) $snapshot->equity_usdc : '0',
            'pnl_today_usdc' => $snapshot?->pnl_today_usdc !== null ? (string) $snapshot->pnl_today_usdc : '0',
            'pnl_total_usdc' => $snapshot?->pnl_total_usdc !== null ? (string) $snapshot->pnl_total_usdc : '0',
            'active_task_count' => $member->copyTasks()->where('status', 1)->count(),
            'recent_orders' => $recentOrders,
        ]);
    }
}
