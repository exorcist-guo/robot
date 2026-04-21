<?php

namespace App\Console\Commands;

use App\Jobs\PmExecuteOrderIntentJob;
use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketTradingService;
use App\Services\Pm\TailSweepMarketDataService;
use App\Services\Pm\TailSweepNextRoundService;
use Illuminate\Console\Command;

class PmPreplaceNextRoundOrderCommand extends Command
{
    protected $signature = 'pm:preplace-next-round-order {--once : 仅执行一次，便于调试}';

    protected $description = '参考模式一信号，提前为下一轮市场创建真实下单意图';

    public function handle(
        GammaClient $gammaClient,
        TailSweepNextRoundService $nextRoundService,
        TailSweepMarketDataService $marketData,
        PolymarketTradingService $trading
    ): int {
        $once = (bool) $this->option('once');

        do {
            $now = now();
            $tasks = PmCopyTask::query()
                ->where('mode', PmCopyTask::MODE_TAIL_SWEEP)
                ->where('status', 1)
                ->where('next_round_enabled', 1)
                ->get();

            foreach ($tasks as $task) {
                $prepared = $nextRoundService->prepare($task, $gammaClient, $now);
                if (($prepared['ok'] ?? false) !== true) {
                    $reason = (string) ($prepared['reason'] ?? 'unknown');
                    $context = collect($prepared)
                        ->except(['ok'])
                        ->map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                        ->all();
                    $this->line('任务 '.$task->id.' 跳过: '.$reason.' '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    continue;
                }

                $targetRoundKey = (string) ($prepared['target_round_key'] ?? '');
                if ($targetRoundKey === '') {
                    continue;
                }

                if ((string) ($task->next_round_last_prepared_round_key ?? '') === $targetRoundKey) {
                    continue;
                }

                $existingIntent = PmOrderIntent::query()
                    ->where('copy_task_id', $task->id)
                    ->where('status', PmOrderIntent::STATUS_PENDING)
                    ->where('risk_snapshot->strategy', 'next_round_preorder')
                    ->where('risk_snapshot->target_round_key', $targetRoundKey)
                    ->first();
                if ($existingIntent) {
                    $task->next_round_last_prepared_round_key = $targetRoundKey;
                    $task->save();
                    continue;
                }

                $predictedSide = (string) $prepared['predicted_side'];
                $side = PolymarketTradingService::SIDE_BUY;
                $tokenId = (string) ($prepared['token_id'] ?? '');
                if ($tokenId === '' || !$trading->isTokenTradable($tokenId)) {
                    continue;
                }

                $books = [];
                [$entryPrice, $entryPriceSource] = $marketData->resolveEntryPrice(
                    $trading,
                    $tokenId,
                    $side,
                    (string) $task->tail_order_usdc,
                    $books
                );
                if (!preg_match('/^\d+(\.\d+)?$/', $entryPrice) || bccomp($entryPrice, '0', 8) <= 0) {
                    continue;
                }

                $nextMarket = is_array($prepared['next_market'] ?? null) ? $prepared['next_market'] : [];
                $intent = PmOrderIntent::create([
                    'copy_task_id' => $task->id,
                    'leader_trade_id' => null,
                    'member_id' => $task->member_id,
                    'token_id' => $tokenId,
                    'side' => $side,
                    'leader_price' => $entryPrice,
                    'target_usdc' => (int) $task->tail_order_usdc,
                    'clamped_usdc' => (int) $task->tail_order_usdc,
                    'status' => PmOrderIntent::STATUS_PENDING,
                    'skip_reason' => null,
                    'risk_snapshot' => [
                        'mode' => PmCopyTask::MODE_TAIL_SWEEP,
                        'strategy' => 'next_round_preorder',
                        'prediction_source' => 'mode1_open_price',
                        'max_slippage_bps' => $task->max_slippage_bps,
                        'allow_partial_fill' => (bool) $task->allow_partial_fill,
                        'daily_max_usdc' => $task->daily_max_usdc,
                        'prediction_round_key' => (string) ($prepared['prediction_round_key'] ?? ''),
                        'target_round_key' => $targetRoundKey,
                        'prev_round_open_price' => (string) ($prepared['prev_round_open_price'] ?? '0'),
                        'current_round_open_price' => (string) ($prepared['current_round_open_price'] ?? '0'),
                        'predict_diff' => (string) ($prepared['predict_diff'] ?? '0'),
                        'predict_abs_diff' => (string) ($prepared['predict_abs_diff'] ?? '0'),
                        'predicted_side' => $predictedSide,
                        'next_round_slug' => (string) ($prepared['next_round_slug'] ?? ''),
                        'next_market_id' => (string) ($nextMarket['market_id'] ?? ''),
                        'next_market_end_at' => (string) ($prepared['next_round_end'] ?? ''),
                        'market_slug' => (string) ($nextMarket['slug'] ?? ''),
                        'market_id' => (string) ($nextMarket['market_id'] ?? ''),
                        'market_question' => (string) ($nextMarket['question'] ?? ''),
                        'resolution_source' => (string) ($nextMarket['resolution_source'] ?? ''),
                        'trigger_side' => $predictedSide,
                        'token_yes_id' => (string) ($nextMarket['token_yes_id'] ?? ''),
                        'token_no_id' => (string) ($nextMarket['token_no_id'] ?? ''),
                        'entry_price' => $entryPrice,
                        'entry_price_source' => $entryPriceSource,
                        'remaining_seconds' => (int) ($prepared['remaining_seconds'] ?? 0),
                    ],
                    'price_time_limit' => 'mode1-next-round',
                ]);

                $task->next_round_last_prepared_round_key = $targetRoundKey;
                $task->save();

                PmExecuteOrderIntentJob::dispatch($intent->id);
                $this->info("任务 {$task->id} 已创建下一轮预下单意图: {$intent->id}");
            }

            if ($once) {
                return self::SUCCESS;
            }

            sleep(5);
        } while (true);
    }
}
