<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmLeaderTrade;

class CopyIntentSizingService
{
    /**
     * @return array<string,mixed>
     */
    public function build(PmCopyTask $task, PmLeaderTrade $trade): array
    {
        $rawTargetUsdc = (int) floor(((int) $trade->size_usdc) * (((int) $task->ratio_bps) / 10000));
        $clampedUsdc = $rawTargetUsdc;
        $clampReason = null;

        if ((int) $task->min_usdc > 0 && $clampedUsdc < (int) $task->min_usdc) {
            $clampedUsdc = (int) $task->min_usdc;
            $clampReason = 'raised_to_min_usdc';
        }

        if ((int) $task->max_usdc > 0 && $clampedUsdc > (int) $task->max_usdc) {
            $clampedUsdc = (int) $task->max_usdc;
            $clampReason = 'capped_by_max_usdc';
        }

        $status = 0;
        $skipReason = null;
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
            'risk_snapshot' => [
                'mode' => (string) $task->mode,
                'strategy_type' => 'ratio_bps',
                'strategy_input' => [
                    'ratio_bps' => (int) $task->ratio_bps,
                    'leader_trade_size_usdc' => (int) $trade->size_usdc,
                ],
                'target_usdc_before_clamp' => $rawTargetUsdc,
                'target_usdc_after_clamp' => $clampedUsdc,
                'clamp_reason' => $clampReason,
                'min_usdc' => (int) $task->min_usdc,
                'max_usdc' => (int) $task->max_usdc,
                'max_slippage_bps' => (int) $task->max_slippage_bps,
                'allow_partial_fill' => (bool) $task->allow_partial_fill,
                'daily_max_usdc' => $task->daily_max_usdc !== null ? (int) $task->daily_max_usdc : null,
                'leader_trade_size_usdc' => (int) $trade->size_usdc,
                'leader_trade_id' => (int) $trade->id,
                'leader_trade_side' => (string) $trade->side,
                'leader_trade_price' => $trade->price,
                'token_id' => (string) ($trade->token_id ?? ''),
                'market_id' => (string) ($trade->market_id ?? ''),
                'generated_at' => now()->toDateTimeString(),
            ],
        ];
    }
}
