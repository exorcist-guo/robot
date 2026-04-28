<?php

namespace App\Jobs;

use App\Models\Pm\PmLeaderTrade;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\CopyIntentSizingService;
use App\Services\Pm\PolymarketDataClient;
use App\Services\Pm\PolymarketTradingService;
use App\Services\Pm\PurchaseTrackingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

    public function handle(
        PolymarketTradingService $trading,
        CopyIntentSizingService $sizing,
        PurchaseTrackingService $purchaseTrackingService,
        PolymarketDataClient $dataClient
    ): void
    {
        $trade = PmLeaderTrade::with('leader.copyTasks')->find($this->leaderTradeId);
        if (!$trade || !$trade->leader) {
            return;
        }

        if (!$trade->token_id || !$trading->isTokenTradable((string) $trade->token_id)) {
            return;
        }

        //通过接口https://data-api.polymarket.com/positions?user=0xbddf61af533ff524d27154e589d2d7a81510c684&sortBy=CURRENT&sortDirection=DESC&sizeThreshold=.1&limit=50
        //获取对应持仓的仓位 size ,并存入 PmLeaderTrade 数据库中,没找打对应的,默认为0
        try {
            $positions = $dataClient->getPositionsByUser((string) $trade->leader->proxy_wallet);
            $positionSize = $dataClient->resolvePositionSizeByToken($positions, (string) $trade->token_id);
        } catch (\Throwable) {
            $positionSize = '0';
        }
        $trade->leader_position_size = $positionSize;
        $trade->save();

        $tasks = $trade->leader->copyTasks()->where('status', 1)->get();
        foreach ($tasks as $task) {
            $sizingResult = $sizing->build($task, $trade);
            $leaderRole = (string) ($trade->leader_role ?? $trade->raw['leader_role'] ?? 'unknown');
            $makerLimit = (string) ($task->maker_max_quantity_per_token ?? '0');
            if (
                $leaderRole === 'maker'
                && strtoupper((string) $trade->side) === PolymarketTradingService::SIDE_BUY
                && preg_match('/^\d+(\.\d+)?$/', $makerLimit) === 1
                && bccomp($makerLimit, '0', 8) > 0
            ) {
                $currentOpenQuantity = $purchaseTrackingService->getOpenQuantityByToken(
                    (int) $task->member_id,
                    (int) $task->id,
                    (string) $trade->token_id
                );
                $pendingBuyQuantity = $purchaseTrackingService->getPendingBuyQuantityByToken(
                    (int) $task->member_id,
                    (int) $task->id,
                    (string) $trade->token_id,
                    $intent?->id
                );
                $plannedQuantity = (string) ($sizingResult['planned_quantity'] ?? '0');
                $nextOpenQuantity = BigDecimal::of($currentOpenQuantity)
                    ->plus(BigDecimal::of($pendingBuyQuantity))
                    ->plus(BigDecimal::of($plannedQuantity))
                    ->toScale(8, RoundingMode::DOWN)
                    ->stripTrailingZeros()
                    ->__toString();

                if (bccomp($nextOpenQuantity, $makerLimit, 8) === 1) {
                    $sizingResult['status'] = PmOrderIntent::STATUS_SKIPPED;
                    $sizingResult['skip_reason'] = 'maker_token_quantity_limit_reached';
                    $sizingResult['risk_snapshot'] = array_merge($sizingResult['risk_snapshot'] ?? [], [
                        'maker_current_open_quantity' => $currentOpenQuantity,
                        'maker_planned_quantity' => $plannedQuantity,
                        'maker_pending_buy_quantity' => $pendingBuyQuantity,
                        'maker_next_open_quantity' => $nextOpenQuantity,
                        'maker_max_quantity_per_token' => $makerLimit,
                    ]);
                }
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
                    'leader_role' => $leaderRole !== '' ? $leaderRole : null,
                    'leader_price' => $trade->price,
                    'target_usdc' => (int) $sizingResult['target_usdc'],
                    'clamped_usdc' => (int) $sizingResult['clamped_usdc'],
                    'status' => (int) $sizingResult['status'],
                    'skip_reason' => $sizingResult['skip_reason'],
                    'skip_category' => $sizingResult['skip_reason'] ? 'sizing' : null,
                    'risk_snapshot' => $sizingResult['risk_snapshot'],
                    'decision_payload' => array_merge(
                        is_array($intent?->decision_payload ?? null) ? $intent->decision_payload : [],
                        ['sizing' => $sizingResult]
                    ),
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
