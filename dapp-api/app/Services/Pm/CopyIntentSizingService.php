<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmLeaderTrade;
use App\Models\Pm\PmOrderIntent;
use App\Models\Pm\PmPurchaseTracking;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class CopyIntentSizingService
{
    /**
     * @return array<string,mixed>
     */
    public function build(PmCopyTask $task, PmLeaderTrade $trade): array
    {
        $rawTargetUsdc = (int) floor(((int) $trade->size_usdc) * (((int) $task->ratio_bps) / 10000));
        $alreadyFollowedQuantity = '0';
        $rawLeaderPositionSize = '0';
        $deltaFollowQuantity = '0';

        if ((string) $trade->leader_role === 'maker') {
            $rawLeaderPositionSize = $this->resolveRawLeaderPositionSize($trade, $task);
            $alreadyFollowedQuantity = $this->resolveAlreadyFollowedQuantity($task, $trade);
            $deltaFollowQuantity = BigDecimal::of($rawLeaderPositionSize)
                ->minus(BigDecimal::of($alreadyFollowedQuantity))
                ->toScale(2, RoundingMode::DOWN)
                ->__toString();

            if (bccomp($deltaFollowQuantity, '0', 2) > 0) {
                $rawTargetUsdc = $this->resolveUsdcFromQuantity($deltaFollowQuantity, (string) $trade->price);
            } else {
                $rawTargetUsdc = 0;
            }
        }

        $clampedUsdc = $rawTargetUsdc;
        $clampReason = null;
        $status = 0;
        $skipReason = null;

        if ((int) $task->min_usdc > 0 && $rawTargetUsdc < (int) $task->min_usdc) {
            $status = 2;
            $skipReason = 'below_min_usdc';
            $clampReason = 'below_min_usdc_skipped';
        }

        if ($status === 0 && (int) $task->max_usdc > 0 && $clampedUsdc > (int) $task->max_usdc) {
            $clampedUsdc = (int) $task->max_usdc;
            $clampReason = 'capped_by_max_usdc';
        }

        if ($rawTargetUsdc <= 0) {
            $status = 2;
            $skipReason = (string) $trade->leader_role === 'maker'
                ? 'maker_follow_quantity_too_small'
                : 'target_usdc_too_small';
        } elseif ($clampedUsdc <= 0) {
            $status = 2;
            $skipReason = 'clamped_usdc_invalid';
        }

        return [
            'target_usdc' => $rawTargetUsdc,
            'clamped_usdc' => $clampedUsdc,
            'status' => $status,
            'skip_reason' => $skipReason,
            'planned_quantity' => $this->resolvePlannedQuantity($trade->price, $clampedUsdc),
            'risk_snapshot' => [
                'mode' => (string) $task->mode,
                'strategy_type' => 'ratio_bps',
                'strategy_input' => [
                    'ratio_bps' => (int) $task->ratio_bps,
                    'leader_trade_size_usdc' => (int) $trade->size_usdc,
                ],
                'target_usdc_before_clamp' => $rawTargetUsdc,
                'target_usdc_after_clamp' => $clampedUsdc,
                'planned_quantity' => $this->resolvePlannedQuantity($trade->price, $clampedUsdc),
                'clamp_reason' => $clampReason,
                'min_usdc' => (int) $task->min_usdc,
                'max_usdc' => (int) $task->max_usdc,
                'max_slippage_bps' => (int) $task->max_slippage_bps,
                'allow_partial_fill' => (bool) $task->allow_partial_fill,
                'daily_max_usdc' => $task->daily_max_usdc !== null ? (int) $task->daily_max_usdc : null,
                'maker_max_quantity_per_token' => $task->maker_max_quantity_per_token !== null ? (string) $task->maker_max_quantity_per_token : null,
                'leader_trade_size_usdc' => (int) $trade->size_usdc,
                'leader_trade_id' => (int) $trade->id,
                'leader_trade_side' => (string) $trade->side,
                'leader_trade_price' => $trade->price,
                'leader_role' => (string) ($trade->leader_role ?? $trade->raw['leader_role'] ?? 'unknown'),
                'token_id' => (string) ($trade->token_id ?? ''),
                'market_id' => (string) ($trade->market_id ?? ''),
                'maker_raw_leader_position_size' => $rawLeaderPositionSize,
                'maker_already_followed_quantity' => $alreadyFollowedQuantity,
                'maker_delta_follow_quantity' => $deltaFollowQuantity,
                'generated_at' => now()->toDateTimeString(),
            ],
        ];
    }

    private function resolvePlannedQuantity(?string $price, int $clampedUsdc): string
    {
        $price = trim((string) $price);
        if ($clampedUsdc <= 0 || $price === '' || preg_match('/^\d+(\.\d+)?$/', $price) !== 1 || bccomp($price, '0', 8) <= 0) {
            return '0';
        }

        return BigDecimal::of((string) $clampedUsdc)
            ->dividedBy('1000000', 8, RoundingMode::DOWN)
            ->dividedBy($price, 8, RoundingMode::DOWN)
            ->stripTrailingZeros()
            ->__toString();
    }

    private function resolveRawLeaderPositionSize(PmLeaderTrade $trade, PmCopyTask $task): string
    {
        $leaderPositionSize = trim((string) $trade->leader_position_size);
        if ($leaderPositionSize === '' || preg_match('/^\d+(\.\d+)?$/', $leaderPositionSize) !== 1) {
            return '0';
        }

        return BigDecimal::of($leaderPositionSize)
            ->multipliedBy((string) $task->ratio_bps)
            ->dividedBy('10000', 2, RoundingMode::DOWN)
            ->__toString();
    }

    private function resolveAlreadyFollowedQuantity(PmCopyTask $task, PmLeaderTrade $trade): string
    {
        $tokenId = trim((string) $trade->token_id);
        if ($tokenId === '') {
            return '0';
        }

        $openQuantity = PmPurchaseTracking::query()
            ->where('member_id', (int) $task->member_id)
            ->where('copy_task_id', (int) $task->id)
            ->where('token_id', $tokenId)
            ->get()
            ->reduce(function (BigDecimal $carry, PmPurchaseTracking $lot) {
                $remaining = trim((string) $lot->remaining_size);
                if ($remaining === '' || preg_match('/^\d+(\.\d+)?$/', $remaining) !== 1 || bccomp($remaining, '0', 8) <= 0) {
                    return $carry;
                }

                return $carry->plus(BigDecimal::of($remaining));
            }, BigDecimal::zero());

        $pendingQuantity = PmOrderIntent::query()
            ->where('member_id', (int) $task->member_id)
            ->where('copy_task_id', (int) $task->id)
            ->where('token_id', $tokenId)
            ->where('side', PolymarketTradingService::SIDE_BUY)
            ->whereIn('status', [PmOrderIntent::STATUS_PENDING, PmOrderIntent::STATUS_SUBMITTED])
            ->where('leader_trade_id', '!=', (int) $trade->id)
            ->get()
            ->reduce(function (BigDecimal $carry, PmOrderIntent $intent) {
                $planned = (string) ($intent->risk_snapshot['planned_quantity'] ?? $intent->decision_payload['sizing']['planned_quantity'] ?? '0');
                if (preg_match('/^\d+(\.\d+)?$/', $planned) !== 1 || bccomp($planned, '0', 8) <= 0) {
                    return $carry;
                }

                return $carry->plus(BigDecimal::of($planned));
            }, BigDecimal::zero());

        return $openQuantity
            ->plus($pendingQuantity)
            ->toScale(8, RoundingMode::DOWN)
            ->stripTrailingZeros()
            ->__toString();
    }

    private function resolveUsdcFromQuantity(string $quantity, ?string $price): int
    {
        $price = trim((string) $price);
        if (preg_match('/^\d+(\.\d+)?$/', $quantity) !== 1 || $price === '' || preg_match('/^\d+(\.\d+)?$/', $price) !== 1 || bccomp($quantity, '0', 8) <= 0 || bccomp($price, '0', 8) <= 0) {
            return 0;
        }

        return (int) BigDecimal::of($quantity)
            ->multipliedBy($price)
            ->multipliedBy('1000000')
            ->toScale(0, RoundingMode::DOWN)
            ->__toString();
    }
}
