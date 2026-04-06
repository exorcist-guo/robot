<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pm\PmMember;
use App\Models\Pm\PmOrder;
use App\Traits\ApiResponseTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use ApiResponseTrait;

    private function currentMember(Request $request): PmMember
    {
        $user = $request->user();

        if (! $user instanceof PmMember) {
            abort(401, '未登录');
        }

        return $user;
    }

    private function baseRecordsQuery(PmMember $member): Builder
    {
        return PmOrder::with(['intent.leaderTrade.leader'])
            ->whereHas('intent', fn ($q) => $q->where('member_id', $member->id));
    }

    private function statusText(int $status): string
    {
        return match ($status) {
            PmOrder::STATUS_SUBMITTED => '已提交',
            PmOrder::STATUS_FILLED => '已成交',
            PmOrder::STATUS_PARTIAL => '部分成交',
            PmOrder::STATUS_CANCELED => '已取消',
            PmOrder::STATUS_REJECTED => '已拒绝',
            PmOrder::STATUS_ERROR => '异常',
            default => '未知状态',
        };
    }

    private function claimStatusText(int $status): string
    {
        return match ($status) {
            PmOrder::CLAIM_STATUS_NOT_NEEDED => '无需兑奖',
            PmOrder::CLAIM_STATUS_PENDING => '待兑奖',
            PmOrder::CLAIM_STATUS_CLAIMING => '兑奖中',
            PmOrder::CLAIM_STATUS_CLAIMED => '已兑奖',
            PmOrder::CLAIM_STATUS_FAILED => '兑奖失败',
            PmOrder::CLAIM_STATUS_SKIPPED => '已跳过',
            PmOrder::CLAIM_STATUS_CONFIRMED => '已确认到账',
            default => '未知状态',
        };
    }

    private function directionText(?string $direction): string
    {
        $value = strtolower(trim((string) $direction));

        return match ($value) {
            'up', 'buy', 'long', 'yes' => '买涨',
            'down', 'sell', 'short', 'no' => '买跌',
            default => $direction ?: '-',
        };
    }

    private function settlementView(PmOrder $order): array
    {
        $isSettled = $order->is_settled;
        $pnlUsdc = $order->pnl_usdc !== null ? (int) $order->pnl_usdc : null;
        $claimStatus = $order->claim_status !== null ? (int) $order->claim_status : null;

        if ($isSettled !== true) {
            return [
                'status' => 'pending_settlement',
                'text' => '未结算',
            ];
        }

        if ($pnlUsdc === null) {
            return [
                'status' => 'settled',
                'text' => '已结算',
            ];
        }

        if ($pnlUsdc < 0) {
            return [
                'status' => 'loss',
                'text' => '已亏损',
            ];
        }

        if ($pnlUsdc > 0) {
            return match ($claimStatus) {
                PmOrder::CLAIM_STATUS_PENDING,
                PmOrder::CLAIM_STATUS_CLAIMING => [
                    'status' => 'claim_pending',
                    'text' => '待兑奖',
                ],
                PmOrder::CLAIM_STATUS_CLAIMED => [
                    'status' => 'claimed',
                    'text' => '已兑奖',
                ],
                default => [
                    'status' => 'profit',
                    'text' => '已盈利',
                ],
            };
        }

        return [
            'status' => 'settled',
            'text' => '已结算',
        ];
    }

    private function transformOrder(PmOrder $order, bool $includePayloads = false): array
    {
        $intent = $order->intent;
        $trade = $intent?->leaderTrade;
        $leader = $trade?->leader;
        $requestPayload = is_array($order->request_payload) ? $order->request_payload : [];
        $direction = $order->outcome
            ?: ($requestPayload['outcome'] ?? null)
            ?: $trade?->side;
        $settlementView = $this->settlementView($order);

        $data = [
            'id' => $order->id,
            'order_id' => $order->id,
            'poly_order_id' => $order->poly_order_id,
            'status' => $order->status,
            'status_text' => $this->statusText((int) $order->status),
            'failure_category' => $order->failure_category,
            'is_retryable' => (bool) ($order->is_retryable ?? false),
            'retry_count' => (int) ($order->retry_count ?? 0),
            'error_code' => $order->error_code,
            'error_message' => $order->error_message,
            'filled_usdc' => (string) ($order->filled_usdc ?? 0),
            'avg_price' => $order->avg_price,
            'original_size' => $order->original_size,
            'filled_size' => $order->filled_size,
            'order_price' => $order->order_price,
            'outcome' => $order->outcome,
            'order_type' => $order->order_type,
            'direction' => $direction,
            'direction_text' => $this->directionText($direction),
            'remote_order_status' => $order->remote_order_status,
            'is_settled' => $order->is_settled,
            'winning_outcome' => $order->winning_outcome,
            'is_win' => $order->is_win,
            'pnl_usdc' => $order->pnl_usdc !== null ? (string) $order->pnl_usdc : null,
            'profit_usdc' => $order->profit_usdc !== null ? (string) $order->profit_usdc : null,
            'roi_bps' => $order->roi_bps,
            'claim_status' => $order->claim_status,
            'claim_status_text' => $this->claimStatusText((int) $order->claim_status),
            'settlement_view_status' => $settlementView['status'],
            'settlement_view_text' => $settlementView['text'],
            'claim_tx_hash' => $order->claim_tx_hash,
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

        if ($includePayloads) {
            $data['request_payload'] = $order->request_payload;
            $data['response_payload'] = $order->response_payload;
            $data['settlement_payload'] = $order->settlement_payload;
            $data['claim_payload'] = $order->claim_payload;
        }

        return $data;
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
                'address' => $wallet->address,
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

        $query = $this->baseRecordsQuery($member);
        $count = (clone $query)->count();
        $list = $query->orderByDesc('id')
            ->forPage($page, $limit)
            ->get()
            ->map(fn (PmOrder $order) => $this->transformOrder($order));

        return $this->success('ok', [
            'count' => $count,
            'page' => $page,
            'limit' => $limit,
            'has_more' => ($page * $limit) < $count,
            'list' => $list,
        ]);
    }

    public function recordDetail(Request $request, int $id)
    {
        $member = $this->currentMember($request);
        $order = $this->baseRecordsQuery($member)->whereKey($id)->first();

        if (! $order) {
            return $this->error('记录不存在');
        }

        return $this->success('ok', $this->transformOrder($order, true));
    }
}
