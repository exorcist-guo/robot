<?php

namespace App\Services\Pm;

use App\Models\Pm\PmTailSweepRoundOpenPrice;
use Illuminate\Support\Carbon;

class SkipRoundPredictService
{
    public function __construct(private readonly TailSweepMarketDataService $marketData)
    {
    }

    /**
     * 隔一轮预测的核心方法。
     *
     * 输入：
     * - 配置里的基础 market slug（如 btc-updown-5m）
     * - 当前时间 now
     * - 配置里的最小预测差值 min_predict_diff
     *
     * 处理流程：
     * 1. 规范化 slug，确认是 5m / 15m 轮次市场
     * 2. 根据当前时间推导上一轮 / 当前轮 / 下一轮的时间边界
     * 3. 从 pm_tail_sweep_round_open_prices 读取上一轮和当前轮开盘价
     * 4. 计算 predict_diff = current_open - prev_open
     * 5. 只有当 |predict_diff| >= min_predict_diff 时才产生信号
     * 6. 若 current_open > prev_open，则预测下一轮为 up，否则为 down
     *
     * 输出：
     * - ok=false：返回具体跳过原因，例如 slug 不支持、开盘价缺失、差值不足阈值
     * - ok=true：返回 signal_round_key / target_round_key / predicted_side / predict_diff 等下游执行所需上下文
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function predict(array $config, Carbon $now): array
    {
        // 读取配置中的基础 market slug，例如 btc-updown-5m。
        $marketSlug = (string) ($config['market_slug'] ?? '');

        // 归一化成不带时间戳的 base slug，便于后续统一推导当前轮/下一轮。
        $baseSlug = $this->marketData->normalizeBaseSlug($marketSlug);
        if ($baseSlug === '') {
            return ['ok' => false, 'reason' => 'missing_market_slug'];
        }

        // 只支持轮次型的 up/down 市场。
        // 普通 event slug 无法从当前时间推导上一轮、当前轮、下一轮，所以直接跳过。
        if (!str_contains($baseSlug, 'updown-5m') && !str_contains($baseSlug, 'updown-15m')) {
            return [
                'ok' => false,
                'reason' => 'unsupported_round_slug',
                'market_slug' => $marketSlug,
            ];
        }

        // 根据 slug 判断市场是 5 分钟轮还是 15 分钟轮。
        // 后面所有时间边界都会基于这个轮次长度来计算。
        $isFifteenMinutes = str_contains($baseSlug, '15m');
        $roundSpan = $isFifteenMinutes ? 900 : 300;

        // 当前轮开始时间：
        // - 5m 市场按当前时间向下取整到最近的 5 分钟
        // - 15m 市场按当前时间向下取整到最近的 15 分钟
        $currentRoundStart = $isFifteenMinutes
            ? $this->marketData->getRoundStartTime15($now)
            : $this->marketData->getRoundStartTime($now);

        // 用当前轮开始时间推导：
        // - 当前轮结束时间
        // - 上一轮开始时间
        // - 下一轮开始/结束时间
        $currentRoundEnd = $currentRoundStart + $roundSpan;
        $prevRoundStart = $currentRoundStart - $roundSpan;
        $nextRoundStart = $currentRoundEnd;
        $nextRoundEnd = $nextRoundStart + $roundSpan;

        // 读取标的，默认 btc/usd。
        $symbol = (string) (($config['symbol'] ?? 'btc/usd') ?: 'btc/usd');

        // 从本地 round_open 表（必要时 fallback 到远端接口）读取：
        // - 上一轮开盘价
        // - 当前轮开盘价
        $prevOpen = $this->getRoundOpenPrice($symbol, $prevRoundStart, $prevRoundStart + $roundSpan);
        $currentOpen = $this->getRoundOpenPrice($symbol, $currentRoundStart, $currentRoundEnd);

        // 任何一边开盘价缺失，都无法做模式1预测，直接返回跳过原因。
        if ($prevOpen === null || $currentOpen === null) {
            return [
                'ok' => false,
                'reason' => 'missing_round_open_price',
                'prev_round_start' => $prevRoundStart,
                'current_round_start' => $currentRoundStart,
            ];
        }

        // 模式1核心：
        // predictDiff = 当前轮开盘价 - 上一轮开盘价
        // 正数表示当前轮相对上一轮在涨，负数表示在跌。
        $predictDiff = bcsub($currentOpen, $prevOpen, 8);

        // 取绝对值，用于和最小预测阈值比较。
        $predictAbsDiff = str_starts_with($predictDiff, '-') ? substr($predictDiff, 1) : $predictDiff;

        // 读取最小预测差值阈值，不合法时回退到默认 26。
        $minPredictDiff = trim((string) ($config['min_predict_diff'] ?? '26'));
        if ($minPredictDiff === '' || preg_match('/^\d+(\.\d+)?$/', $minPredictDiff) !== 1) {
            $minPredictDiff = '26';
        }

        // 如果当前轮相对上一轮的价格变化还不够大，就不触发隔一轮预测。
        if (bccomp($predictAbsDiff, $minPredictDiff, 8) === -1) {
            return [
                'ok' => false,
                'reason' => 'predict_diff_below_threshold',
                'predict_diff' => $predictDiff,
                'predict_abs_diff' => $predictAbsDiff,
                'min_predict_diff' => $minPredictDiff,
                'prediction_round_key' => (string) $currentRoundEnd,
                'target_round_key' => (string) $nextRoundEnd,
            ];
        }

        // 生成成功结果：
        // - signal/prediction_round_key 取当前轮结束时间
        // - target_round_key 取下一轮结束时间
        // - predicted_side：当前轮相对上一轮涨 => 预测下一轮 up，否则 down
        return [
            'ok' => true,
            'base_slug' => $baseSlug,
            'prediction_round_key' => (string) $currentRoundEnd,
            'signal_round_key' => (string) $currentRoundEnd,
            'target_round_key' => (string) $nextRoundEnd,
            'prev_round_start' => $prevRoundStart,
            'current_round_start' => $currentRoundStart,
            'current_round_end' => $currentRoundEnd,
            'next_round_start' => $nextRoundStart,
            'next_round_end' => $nextRoundEnd,
            'remaining_seconds' => max(0, $now->diffInSeconds(Carbon::createFromTimestamp($currentRoundEnd), false)),
            'prev_round_open_price' => $prevOpen,
            'current_round_open_price' => $currentOpen,
            'predict_diff' => $predictDiff,
            'predict_abs_diff' => $predictAbsDiff,
            'predicted_side' => bccomp($currentOpen, $prevOpen, 8) === 1 ? 'up' : 'down',
        ];
    }

    private function getRoundOpenPrice(string $symbol, int $roundStartAt, int $roundEndAt): ?string
    {
        $price = PmTailSweepRoundOpenPrice::query()
            ->where('symbol', $symbol)
            ->where('round_start_at', Carbon::createFromTimestamp($roundStartAt))
            ->value('round_open_price');

        if (is_string($price) && $price !== '') {
            return $price;
        }

        $fallback = $this->marketData->getStartPrice($roundStartAt, $roundEndAt, $symbol);
        return $fallback !== '0' ? $fallback : null;
    }
}
