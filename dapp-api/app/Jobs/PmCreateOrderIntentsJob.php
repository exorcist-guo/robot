<?php

namespace App\Jobs;

use App\Models\Pm\PmLeaderTrade;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\CopyIntentSizingService;
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
        PurchaseTrackingService $purchaseTrackingService
    ): void
    {
        $trade = PmLeaderTrade::with('leader.copyTasks')->find($this->leaderTradeId);
        if (!$trade || !$trade->leader) {
            return;
        }

        if (!$trade->token_id || !$trading->isTokenTradable((string) $trade->token_id)) {
            return;
        }

        $trade->leader_position_size = $this->resolveLeaderPositionSizeFromLocalTrades($trade);
        $trade->save();

        $tasks = $trade->leader->copyTasks()->where('status', 1)->get();
        foreach ($tasks as $task) {
            $existingIntent = PmOrderIntent::query()
                ->where('copy_task_id', (int) $task->id)
                ->where('leader_trade_id', (int) $trade->id)
                ->first();

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
                    $existingIntent?->id
                );
                $plannedQuantity = (string) ($sizingResult['planned_quantity'] ?? '0');
                $nextOpenQuantity = BigDecimal::of($currentOpenQuantity)
                    ->plus(BigDecimal::of($pendingBuyQuantity))
                    ->plus(BigDecimal::of($plannedQuantity))
                    ->toScale(8, RoundingMode::DOWN)
                    ->stripTrailingZeros()
                    ->__toString();

                if (bccomp($nextOpenQuantity, $makerLimit, 8) === 1) {
                    $remainingQuantity = BigDecimal::of($makerLimit)
                        ->minus(BigDecimal::of($currentOpenQuantity))
                        ->minus(BigDecimal::of($pendingBuyQuantity));

                    if ($remainingQuantity->isGreaterThan(BigDecimal::zero())) {
                        $adjustedPlannedQuantity = $remainingQuantity->isLessThan(BigDecimal::of('5'))
                            ? '5'
                            : $remainingQuantity->toScale(2, RoundingMode::DOWN)->stripTrailingZeros()->__toString();
                        $adjustedTargetUsdc = $this->resolveUsdcFromQuantityAndPrice($adjustedPlannedQuantity, (string) $trade->price);
                        $adjustedNextOpenQuantity = BigDecimal::of($currentOpenQuantity)
                            ->plus(BigDecimal::of($pendingBuyQuantity))
                            ->plus(BigDecimal::of($adjustedPlannedQuantity))
                            ->toScale(8, RoundingMode::DOWN)
                            ->stripTrailingZeros()
                            ->__toString();

                        $sizingResult['target_usdc'] = $adjustedTargetUsdc;
                        $sizingResult['clamped_usdc'] = $adjustedTargetUsdc;
                        $sizingResult['planned_quantity'] = $adjustedPlannedQuantity;
                        $sizingResult['risk_snapshot'] = array_merge($sizingResult['risk_snapshot'] ?? [], [
                            'maker_current_open_quantity' => $currentOpenQuantity,
                            'maker_planned_quantity' => $plannedQuantity,
                            'maker_pending_buy_quantity' => $pendingBuyQuantity,
                            'maker_next_open_quantity' => $nextOpenQuantity,
                            'maker_remaining_quantity' => $remainingQuantity->toScale(8, RoundingMode::DOWN)->stripTrailingZeros()->__toString(),
                            'maker_adjusted_planned_quantity' => $adjustedPlannedQuantity,
                            'maker_adjusted_next_open_quantity' => $adjustedNextOpenQuantity,
                            'maker_adjusted_target_usdc' => $adjustedTargetUsdc,
                            'maker_limit_action' => 'clip_to_remaining_quantity',
                            'maker_max_quantity_per_token' => $makerLimit,
                        ]);
                    } else {
                        $sizingResult['status'] = PmOrderIntent::STATUS_SKIPPED;
                        $sizingResult['skip_reason'] = 'maker_token_quantity_limit_reached';
                        $sizingResult['risk_snapshot'] = array_merge($sizingResult['risk_snapshot'] ?? [], [
                            'maker_current_open_quantity' => $currentOpenQuantity,
                            'maker_planned_quantity' => $plannedQuantity,
                            'maker_pending_buy_quantity' => $pendingBuyQuantity,
                            'maker_next_open_quantity' => $nextOpenQuantity,
                            'maker_remaining_quantity' => $remainingQuantity->toScale(8, RoundingMode::DOWN)->stripTrailingZeros()->__toString(),
                            'maker_limit_action' => 'skip_no_remaining_quantity',
                            'maker_max_quantity_per_token' => $makerLimit,
                        ]);
                    }
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
                        is_array($existingIntent?->decision_payload ?? null) ? $existingIntent->decision_payload : [],
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

    private function resolveLeaderPositionSizeFromLocalTrades(PmLeaderTrade $trade): string
    {
        $tokenId = trim((string) $trade->token_id);
        if ($tokenId === '') {
            return '0';
        }

        $total = PmLeaderTrade::query()
            ->where('leader_id', (int) $trade->leader_id)
            ->where('token_id', $tokenId)
            ->where(function ($query) use ($trade) {
                $query->where('traded_at', '<', (int) $trade->traded_at)
                    ->orWhere(function ($nested) use ($trade) {
                        $nested->where('traded_at', (int) $trade->traded_at)
                            ->where('id', '<=', (int) $trade->id);
                    });
            })
            ->orderBy('traded_at')
            ->orderBy('id')
            ->get()
            ->reduce(function (BigDecimal $carry, PmLeaderTrade $item) {
                $size = $this->resolveSignedTradeSize($item);
                if ($size === null) {
                    return $carry;
                }

                return $carry->plus(BigDecimal::of($size));
            }, BigDecimal::zero());

        if ($total->isLessThanOrEqualTo(BigDecimal::zero())) {
            return '0';
        }

        return $total->toScale(8, RoundingMode::DOWN)->stripTrailingZeros()->__toString();
    }

    private function resolveSignedTradeSize(PmLeaderTrade $trade): ?string
    {
        $size = trim((string) ($trade->size ?? ''));
        if (preg_match('/^-?\d+(\.\d+)?$/', $size) === 1 && bccomp($size, '0', 8) !== 0) {
            return $size;
        }

        $rawSize = trim((string) ($trade->raw['size'] ?? ''));
        if (preg_match('/^\d+(\.\d+)?$/', $rawSize) !== 1) {
            return null;
        }

        return strtoupper((string) $trade->side) === PolymarketTradingService::SIDE_SELL
            ? '-' . $rawSize
            : $rawSize;
    }

    private function resolveUsdcFromQuantityAndPrice(string $quantity, string $price): int
    {
        if (
            preg_match('/^\d+(\.\d+)?$/', $quantity) !== 1
            || preg_match('/^\d+(\.\d+)?$/', $price) !== 1
            || bccomp($quantity, '0', 8) <= 0
            || bccomp($price, '0', 8) <= 0
        ) {
            return 0;
        }

        return (int) BigDecimal::of($quantity)
            ->multipliedBy($price)
            ->multipliedBy('1000000')
            ->toScale(0, RoundingMode::DOWN)
            ->__toString();
    }
}
