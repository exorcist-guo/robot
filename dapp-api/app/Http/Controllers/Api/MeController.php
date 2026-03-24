<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pm\PmMember;
use App\Models\Pm\PmOrder;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use ApiResponseTrait;

    private function currentMember(Request $request): PmMember
    {
        /** @var PmMember $user */
        $user = $request->user();
        return $user;
    }

    public function profile(Request $request)
    {
        $member = $this->currentMember($request);
        $wallet = $member->custodyWallet;

        return $this->success('ok', [
            'id' => $member->id,
            'address' => $member->address,
            'nickname' => $member->nickname,
            'avatar_url' => $member->avatar_url,
            'inviter_id' => $member->inviter_id,
            'wallet' => $wallet ? [
                'signer_address' => $wallet->signer_address,
                'funder_address' => $wallet->funder_address,
                'signature_type' => $wallet->signature_type,
                'status' => $wallet->status,
            ] : null,
        ]);
    }

    public function records(Request $request)
    {
        $member = $this->currentMember($request);
        $page = max(1, (int) $request->query('page', 1));
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        $query = PmOrder::with(['intent.leaderTrade.leader'])
            ->whereHas('intent', fn ($q) => $q->where('member_id', $member->id));

        $count = (clone $query)->count();
        $list = $query->orderByDesc('id')
            ->forPage($page, $limit)
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
                    'id' => $order->id,
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
                    'profit_usdc' => '0',
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
            'count' => $count,
            'list' => $list,
        ]);
    }
}
