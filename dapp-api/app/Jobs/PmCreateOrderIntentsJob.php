<?php

namespace App\Jobs;

use App\Models\Pm\PmLeaderTrade;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\CopyIntentSizingService;
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

    public function handle(PolymarketTradingService $trading, CopyIntentSizingService $sizing): void
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
            $sizingResult = $sizing->build($task, $trade);

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
                    'target_usdc' => (int) $sizingResult['target_usdc'],
                    'clamped_usdc' => (int) $sizingResult['clamped_usdc'],
                    'status' => (int) $sizingResult['status'],
                    'skip_reason' => $sizingResult['skip_reason'],
                    'skip_category' => $sizingResult['skip_reason'] ? 'sizing' : null,
                    'risk_snapshot' => $sizingResult['risk_snapshot'],
                    'decision_payload' => [
                        'sizing' => $sizingResult,
                    ],
                    'execution_mode' => (bool) config('pm.copy_dry_run', false) ? 'dry_run' : 'live',
                    'execution_stage' => 'queued',
                ]
            );

            if ($intent->status === 0 && !$intent->order) {
                PmExecuteOrderIntentJob::dispatch($intent->id);
            }
        }
    }
}
