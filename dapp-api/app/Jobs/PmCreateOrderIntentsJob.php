<?php

namespace App\Jobs;

use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmLeaderTrade;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\PolymarketTradingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PmCreateOrderIntentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $leaderTradeId)
    {
    }

    public function handle(PolymarketTradingService $trading): void
    {
        $trade = PmLeaderTrade::with('leader.copyTasks')->find($this->leaderTradeId);
        if (!$trade || !$trade->leader) {
            return;
        }

        if (!$trade->token_id || !$trading->isTokenTradable((string) $trade->token_id)) {
            return;
        }

        $tasks = $trade->leader->copyTasks()->where('status', 1)->get();
        foreach ($tasks as $task) {
            $targetUsdc = (int) floor($trade->size_usdc * ($task->ratio_bps / 10000));
            $clampedUsdc = $targetUsdc;

            if ($task->min_usdc > 0 && $clampedUsdc < $task->min_usdc) {
                $clampedUsdc = (int) $task->min_usdc;
            }
            if ($task->max_usdc > 0 && $clampedUsdc > $task->max_usdc) {
                $clampedUsdc = (int) $task->max_usdc;
            }

            $riskSnapshot = [
                'ratio_bps' => $task->ratio_bps,
                'min_usdc' => $task->min_usdc,
                'max_usdc' => $task->max_usdc,
                'max_slippage_bps' => $task->max_slippage_bps,
                'allow_partial_fill' => (bool) $task->allow_partial_fill,
                'daily_max_usdc' => $task->daily_max_usdc,
                'leader_trade_size_usdc' => $trade->size_usdc,
            ];

            $status = 0;
            $skipReason = null;
            if ($targetUsdc <= 0) {
                $status = 2;
                $skipReason = 'target_usdc_too_small';
            } elseif ($clampedUsdc <= 0) {
                $status = 2;
                $skipReason = 'clamped_usdc_invalid';
            }

            $intent = PmOrderIntent::updateOrCreate(
                [
                    'copy_task_id' => $task->id,
                    'leader_trade_id' => $trade->id,
                ],
                [
                    'member_id' => $task->member_id,
                    'token_id' => (string) $trade->token_id,
                    'side' => (string) $trade->side,
                    'leader_price' => $trade->price,
                    'target_usdc' => $targetUsdc,
                    'clamped_usdc' => $clampedUsdc,
                    'status' => $status,
                    'skip_reason' => $skipReason,
                    'risk_snapshot' => $riskSnapshot,
                ]
            );

            if ($intent->status === 0 && !$intent->order) {
                PmExecuteOrderIntentJob::dispatch($intent->id);
            }
        }
    }
}
