<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmLeaderTrade;
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
        if($trade->leader_role == 'maker'){
            //跟单数量
            $raw_leader_position_size = bcdiv($trade->leader_position_size * $task->ratio_bps,10000,2);
            //完善功能,通过  $raw_leader_position_size - pm_leader_trades 中已跟单的数量,得到本次跟单的数量,并转换为 usdc 数量就是 $rawTargetUsdc



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
            $skipReason = 'target_usdc_too_small';
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
}
