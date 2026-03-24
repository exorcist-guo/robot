<?php

namespace App\Console\Commands;

use App\Jobs\PmExecuteOrderIntentJob;
use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\PolymarketTradingService;
use App\Services\Pm\TailSweepPriceCache;
use Illuminate\Console\Command;

class PmScanTailSweepCommand extends Command
{
    // 单次执行扫尾盘扫描，由调度器按固定频率触发。
    protected $signature = 'pm:scan-tail-sweep';

    protected $description = '扫描扫尾盘任务并在满足条件时生成下单意图';

    public function handle(TailSweepPriceCache $priceCache, PolymarketTradingService $trading): int
    {
        // 执行一轮扫描；命令本身不常驻循环。
        $this->scan($priceCache, $trading);
        $this->info('扫尾盘扫描完成');

        return self::SUCCESS;
    }

    private function scan(TailSweepPriceCache $priceCache, PolymarketTradingService $trading): void
    {
        // 固定本轮扫描时间基准，避免循环内多次 now() 导致边界判断漂移。
        $now = now();

        // 只加载启用中的扫尾盘任务，并裁剪为本轮计算真正需要的字段。
        $tasks = PmCopyTask::query()
            ->where('mode', PmCopyTask::MODE_TAIL_SWEEP)
            ->where('status', 1)
            ->whereNotNull('market_end_at')
            ->where('market_end_at', '>', $now)
            ->get([
                'id',
                'member_id',
                'status',
                'market_slug',
                'market_id',
                'market_question',
                'market_symbol',
                'resolution_source',
                'price_to_beat',
                'market_end_at',
                'token_yes_id',
                'token_no_id',
                'tail_order_usdc',
                'tail_trigger_amount',
                'tail_time_limit_seconds',
                'tail_loss_stop_count',
                'tail_loss_count',
                'tail_round_started_value',
                'tail_last_triggered_round_key',
                'tail_loss_stopped_at',
                'max_slippage_bps',
                'allow_partial_fill',
                'daily_max_usdc',
            ])
            // 先粗筛出“接近尾盘窗口”的任务，减少后续外部行情请求。
            ->filter(fn (PmCopyTask $task) => $now->diffInSeconds($task->market_end_at, false) <= ((int) $task->tail_time_limit_seconds + 15));

        // 同一轮扫描内按 symbol / token+side 做缓存，避免重复请求外部数据。
        $snapshots = [];
        $books = [];

        foreach ($tasks as $task) {
            $marketEndAt = $task->market_end_at;
            if (!$marketEndAt) {
                continue;
            }

            // 距离市场结束的剩余秒数，后续所有尾盘判断都基于这个值。
            $remainingSeconds = $now->diffInSeconds($marketEndAt, false);
            if ($remainingSeconds <= 0) {
                // 本轮已经结束时，清空本轮起始值与触发标记，便于下一轮重新开始。
                if ($task->tail_round_started_value !== null || $task->tail_last_triggered_round_key !== null) {
                    $task->tail_round_started_value = null;
                    $task->tail_last_triggered_round_key = null;
                    $task->save();
                }
                continue;
            }

            // 达到累计亏损停单阈值后，自动暂停任务并记录停单时间。
            if ($task->tail_loss_stop_count > 0 && $task->tail_loss_count >= $task->tail_loss_stop_count) {
                if ($task->status !== 0 || $task->tail_loss_stopped_at === null) {
                    $task->status = 0;
                    $task->tail_loss_stopped_at = $now;
                    $task->save();
                }
                continue;
            }

            // 只有进入最后 N 秒触发窗口后，才继续做价格变化判断。
            if ($remainingSeconds > (int) $task->tail_time_limit_seconds) {
                continue;
            }

            // 默认标的是 btc/usd；同一 symbol 在本轮扫描内只读取一次共享缓存。
            $symbol = $priceCache->normalizeSymbol((string) ($task->market_symbol ?: 'btc/usd'));
            if (!array_key_exists($symbol, $snapshots)) {
                $snapshot = $priceCache->getSnapshot($symbol);
                if (!$priceCache->isFresh($snapshot)) {
                    $snapshots[$symbol] = null;
                    $this->warn("标的 {$symbol} 缓存行情缺失或已过期，跳过本轮扫描");
                } else {
                    $snapshots[$symbol] = $snapshot;
                }
            }

            $snapshot = $snapshots[$symbol];
            if (!is_array($snapshot)) {
                continue;
            }

            // currentPrice 是实时价格；priceToBeat 是市场设定的结算基准价。
            $currentPrice = (string) ($snapshot['value'] ?? '0');
            $priceToBeat = (string) ($task->price_to_beat ?: '0');
            if (!preg_match('/^\d+(\.\d+)?$/', $currentPrice) || !preg_match('/^\d+(\.\d+)?$/', $priceToBeat)) {
                continue;
            }

            // 以 end_at 时间戳作为轮次 key，同一轮只允许触发一次。
            $roundKey = (string) $marketEndAt->timestamp;
            $currentDelta = bcsub($currentPrice, $priceToBeat, 8);
            if ($task->tail_round_started_value === null) {
                // 首次进入本轮尾盘窗口时，记录起始差值作为后续涨跌比较基准。
                $task->tail_round_started_value = $currentDelta;
                $task->save();
            }

            // 本轮已经触发过则直接跳过，避免重复下单。
            if ($task->tail_last_triggered_round_key === $roundKey) {
                continue;
            }

            $startDelta = (string) ($task->tail_round_started_value ?? $currentDelta);
            // 变化量 = 当前差值 - 本轮开始差值。
            $change = bcsub($currentDelta, $startDelta, 8);
            $threshold = (string) ($task->tail_trigger_amount ?: '0');
            if (!preg_match('/^\d+(\.\d+)?$/', $threshold) || bccomp($threshold, '0', 8) <= 0) {
                continue;
            }

            $side = null;
            $tokenId = null;
            $triggerSide = null;
            if (bccomp($change, $threshold, 8) >= 0) {
                // 涨幅达到阈值：买上涨方向 token。
                $side = PolymarketTradingService::SIDE_BUY;
                $tokenId = (string) $task->token_yes_id;
                $triggerSide = 'up';
            } elseif (bccomp($change, bcmul($threshold, '-1', 8), 8) <= 0) {
                // 跌幅达到阈值：买下跌方向 token。
                $side = PolymarketTradingService::SIDE_BUY;
                $tokenId = (string) $task->token_no_id;
                $triggerSide = 'down';
            }

            if (!$side || !$triggerSide || $tokenId === '') {
                continue;
            }

            // 同一 token + side 在本轮扫描里复用订单簿最优价。
            $bookKey = $tokenId.'|'.$side;
            if (!isset($books[$bookKey])) {
                $books[$bookKey] = $trading->getOrderBookBestPrice($tokenId, $side);
            }
            $book = $books[$bookKey];
            $entryPrice = (string) ($book['price'] ?? '0');
            if (!preg_match('/^\d+(\.\d+)?$/', $entryPrice) || bccomp($entryPrice, '0', 8) <= 0) {
                continue;
            }

            // 同一任务同一轮若已有 pending 意图，则不再重复创建。
            $existingIntent = PmOrderIntent::query()
                ->where('copy_task_id', $task->id)
                ->where('status', PmOrderIntent::STATUS_PENDING)
                ->where('risk_snapshot->round_key', $roundKey)
                ->first();
            if ($existingIntent) {
                continue;
            }

            // 创建待执行下单意图，并把本轮触发上下文完整写入 risk_snapshot。
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
                    'max_slippage_bps' => $task->max_slippage_bps,
                    'allow_partial_fill' => (bool) $task->allow_partial_fill,
                    'daily_max_usdc' => $task->daily_max_usdc,
                    'round_key' => $roundKey,
                    'market_slug' => $task->market_slug,
                    'market_id' => $task->market_id,
                    'market_question' => $task->market_question,
                    'resolution_source' => $task->resolution_source,
                    'trigger_side' => $triggerSide,
                    'trigger_amount' => $threshold,
                    'current_price' => $currentPrice,
                    'price_to_beat' => $priceToBeat,
                    'current_delta' => $currentDelta,
                    'start_delta' => $startDelta,
                    'change' => $change,
                    'remaining_seconds' => $remainingSeconds,
                    'token_yes_id' => $task->token_yes_id,
                    'token_no_id' => $task->token_no_id,
                    'entry_price' => $entryPrice,
                ],
            ]);

            // 标记当前轮次已触发，防止本轮再次生成意图。
            $task->tail_last_triggered_round_key = $roundKey;
            $task->save();

            // 交给统一下单执行任务异步处理。
            PmExecuteOrderIntentJob::dispatch($intent->id);
            $this->info("任务 {$task->id} 已触发扫尾盘下单");
        }
    }
}
