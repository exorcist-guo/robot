<?php

namespace App\Console\Commands;

use App\Models\Pm\PmTailSweepMarketSnapshot;
use App\Models\Pm\PmTailSweepRoundOpenPrice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PmBacktestTailSweepCommand extends Command
{
    protected $signature = 'pm:backtest-tail-sweep
        {period : day|week|month}
        {--mode=1 : 仅支持 1 或 2}
        {--symbol=btc/usd : 默认标的}
        {--amount=20 : 模式二买入金额，默认 20 USDC}
        {--detail : 输出明细}';

    protected $description = '使用历史数据回测 tail sweep 策略';

    public function handle(): int
    {
        [$startAt, $endAt] = $this->resolveWindow();
        $mode = (string) $this->option('mode');
        $symbol = trim((string) $this->option('symbol')) ?: 'btc/usd';

        if (!in_array($mode, ['1', '2'], true)) {
            if ($mode === '3') {
                $this->warn('模式三暂未开发');

                return self::SUCCESS;
            }

            $this->error('mode 仅支持 1 或 2');

            return self::FAILURE;
        }

        if ($mode === '1') {
            $result = $this->runMode1($symbol, $startAt, $endAt);
            $this->printMode1($result);

            return self::SUCCESS;
        }

        $amount = $this->normalizePositiveDecimal((string) $this->option('amount'), 6);
        if ($amount === null) {
            $this->error('amount 必须是大于 0 的数字');

            return self::FAILURE;
        }

        $result = $this->backtestMode2($symbol, $startAt, $endAt, $amount, null, (bool) $this->option('detail'));
        $this->printMode2($result);

        return self::SUCCESS;
    }

    private function runMode1(string $symbol, Carbon $startAt, Carbon $endAt): array
    {
        $rows = PmTailSweepRoundOpenPrice::query()
            ->where('symbol', $symbol)
            ->where('round_start_at', '>=', $startAt)
            ->where('round_start_at', '<', $endAt)
            ->whereNotNull('round_open_price')
            ->orderBy('id')
            ->get(['id', 'round_start_at', 'round_end_at', 'round_open_price'])
            ->values();

        $details = [];
        $winCount = 0;
        $loseCount = 0;
        $sampleCount = 0;
        $prediction = null;
        $baseBet = '5';
        $maxLoseResetLimit = 6;
        $resetLoseCount = 0;
        $currentBet = $baseBet;
        $maxBet = $baseBet;
        $netProfit = '0';
        $totalStake = '0';
        $currentLoseStreak = 0;
        $maxLoseStreak = 0;
        $currentFundingNeed = '0';
        $maxFundingNeed = '0';
        $loseStreakStats = [];
        $minPredictDiff = '10';

        for ($i = 1; $i < $rows->count(); $i++) {
            $prevOpen = (string) $rows[$i - 1]->round_open_price;
            $currentOpen = (string) $rows[$i]->round_open_price;

            if (!$this->isPositiveDecimal($prevOpen) || !$this->isPositiveDecimal($currentOpen)) {
                continue;
            }

            $actualDirection = bccomp($currentOpen, $prevOpen, 8) === 1 ? 'up' : 'down';
            $priceDiffAbs = $this->bcAbs($this->bcSub($currentOpen, $prevOpen, 8));
            $result = null;

            if ($prediction !== null) {
                $sampleCount++;
                $result = $prediction === $actualDirection ? 'win' : 'lose';
                $betAmount = $currentBet;
                $totalStake = $this->bcAdd($totalStake, $betAmount, 8);
                $currentFundingNeed = $this->bcAdd($currentFundingNeed, $betAmount, 8);
                if (bccomp($currentFundingNeed, $maxFundingNeed, 8) === 1) {
                    $maxFundingNeed = $currentFundingNeed;
                }

                if ($result === 'win') {
                    if ($currentLoseStreak > 2) {
                        $loseStreakStats[$currentLoseStreak] = ($loseStreakStats[$currentLoseStreak] ?? 0) + 1;
                    }
                    $winCount++;
                    $netProfit = $this->bcAdd($netProfit, $betAmount, 8);
                    $currentBet = $baseBet;
                    $currentLoseStreak = 0;
                    $currentFundingNeed = '0';
                } else {
                    $loseCount++;
                    $netProfit = $this->bcSub($netProfit, $betAmount, 8);
                    $currentLoseStreak++;
                    if ($currentLoseStreak > $maxLoseStreak) {
                        $maxLoseStreak = $currentLoseStreak;
                    }

                    if ($currentLoseStreak > $maxLoseResetLimit) {
                        $resetLoseCount++;
                        if ($currentLoseStreak > 2) {
                            $loseStreakStats[$currentLoseStreak] = ($loseStreakStats[$currentLoseStreak] ?? 0) + 1;
                        }
                        $currentLoseStreak = 0;
                        $currentFundingNeed = '0';
                        $currentBet = $baseBet;
                    } else {
                        $currentBet = bcmul($betAmount, '2', 8);
                        if (bccomp($currentBet, $maxBet, 8) === 1) {
                            $maxBet = $currentBet;
                        }
                    }
                }
            }

            $nextPrediction = bccomp($priceDiffAbs, $minPredictDiff, 8) === -1 ? null : $actualDirection;

            if ((bool) $this->option('detail')) {
                $details[] = [
                    'id' => $rows[$i]->id,
                    'round_start_at' => optional($rows[$i]->round_start_at)->toDateTimeString(),
                    'prev_open' => $prevOpen,
                    'current_open' => $currentOpen,
                    'prediction' => $prediction ?? 'skip',
                    'actual' => $actualDirection,
                    '价差绝对值' => $priceDiffAbs,
                    'bet_amount' => $prediction !== null ? $betAmount : '0',
                    'result' => $result ?? 'skip',
                    'total_stake' => $totalStake,
                    'net_profit' => $netProfit,
                    'profit_ratio' => bccomp($totalStake, '0', 8) === 1 ? $this->formatBcPercent($netProfit, $totalStake, 4) : '0.00%',
                    'lose_streak' => $prediction !== null ? (string) $currentLoseStreak : '0',
                    'funding_need' => $prediction !== null ? $currentFundingNeed : '0',
                    'reset_lose_count' => (string) $resetLoseCount,
                    'next_prediction' => $nextPrediction ?? 'skip',
                    'next_bet_amount' => $nextPrediction !== null ? $currentBet : '0',
                ];
            }

            $prediction = $nextPrediction;
        }

        if ($currentLoseStreak > 2) {
            $loseStreakStats[$currentLoseStreak] = ($loseStreakStats[$currentLoseStreak] ?? 0) + 1;
        }

        ksort($loseStreakStats);
        $loseStreakSummary = [];
        foreach ($loseStreakStats as $streak => $count) {
            $loseStreakSummary[] = "连亏{$streak}次:{$count}";
        }

        return [
            'mode' => '1',
            'symbol' => $symbol,
            'window_start' => $startAt->toDateTimeString(),
            'window_end' => $endAt->toDateTimeString(),
            'base_bet' => $baseBet,
            'total_rounds' => $rows->count(),
            'valid_samples' => $sampleCount,
            'win_count' => $winCount,
            'lose_count' => $loseCount,
            'win_rate' => $sampleCount > 0 ? $this->formatPercent($winCount, $sampleCount) : '0.00%',
            'total_stake' => $totalStake,
            'net_profit' => $netProfit,
            'profit_ratio' => bccomp($totalStake, '0', 8) === 1 ? $this->formatBcPercent($netProfit, $totalStake, 4) : '0.00%',
            'max_bet_amount' => $maxBet,
            'max_lose_streak' => $maxLoseStreak,
            'max_funding_need' => $maxFundingNeed,
            'max_lose_reset_limit' => $maxLoseResetLimit,
            'reset_lose_count' => $resetLoseCount,
            'lose_streak_summary' => $loseStreakSummary,
            'details' => $details,
        ];
    }

    public function backtestMode2(string $symbol, Carbon $startAt, Carbon $endAt, string $amount, ?array $config = null, bool $detail = false): array
    {
        $config = $config ?? ($this->defaultConfig()[$symbol] ?? null);
        if (!$config) {
            return [
                'mode' => '2',
                'symbol' => $symbol,
                'window_start' => $startAt->toDateTimeString(),
                'window_end' => $endAt->toDateTimeString(),
                'scanned_snapshots' => 0,
                'valid_rounds' => 0,
                'triggered_rounds' => 0,
                'executed_rounds' => 0,
                'skipped_rounds' => 0,
                'win_count' => 0,
                'lose_count' => 0,
                'win_rate' => '0.00%',
                'total_stake' => '0',
                'net_profit' => '0',
                'roi' => '0.00%',
                'details' => [],
                'message' => "标的 {$symbol} 没有默认配置",
            ];
        }

        $rounds = $this->loadMode2Rounds($symbol, $startAt, $endAt);
        $snapshots = $this->loadMode2Snapshots($symbol, $startAt, $endAt);

        $details = [];
        $triggeredRounds = 0;
        $executedRounds = 0;
        $skippedRounds = 0;
        $winCount = 0;
        $loseCount = 0;
        $netProfit = '0';
        $totalStake = '0';
        $doneRounds = [];
        $roundIndex = 0;
        $roundCount = count($rounds);

        foreach ($snapshots as $snapshot) {
            $snapshotAt = $snapshot->snapshot_at;
            if (!$snapshotAt) {
                continue;
            }

            while ($roundIndex < $roundCount && $snapshotAt->gte($rounds[$roundIndex]['round_end_at'])) {
                $roundIndex++;
            }

            if ($roundIndex >= $roundCount) {
                break;
            }

            $round = $rounds[$roundIndex];
            if ($snapshotAt->lt($round['round_start_at'])) {
                continue;
            }

            if (isset($doneRounds[$round['key']])) {
                continue;
            }

            $currentPrice = trim((string) $snapshot->current_price);
            if (!$this->isPositiveDecimal($currentPrice)) {
                continue;
            }

            $priceDiff = $this->bcSub($currentPrice, $round['start_price'], 8);
            if (bccomp($priceDiff, '0', 8) === 0) {
                continue;
            }

            $absDiff = $this->bcAbs($priceDiff);
            $remainingSeconds = $snapshotAt->diffInSeconds($round['round_end_at'], false);
            if ($remainingSeconds < 5) {
                continue;
            }

            $matchedRule = null;
            foreach ($config as $threshold => $timeLimit) {
                $thresholdValue = (string) $threshold;
                if (bccomp($absDiff, $thresholdValue, 8) === 1 && $remainingSeconds < (int) $timeLimit) {
                    $matchedRule = [$thresholdValue, (int) $timeLimit];
                    break;
                }
            }

            if ($matchedRule === null) {
                continue;
            }

            $triggeredRounds++;
            $doneRounds[$round['key']] = true;

            $side = bccomp($priceDiff, '0', 8) === 1 ? 'up' : 'down';
            $entryPrice = $side === 'up'
                ? trim((string) $snapshot->up_entry_price5m)
                : trim((string) $snapshot->down_entry_price5m);

            if (!$this->isValidEntryPrice($entryPrice)) {
                $skippedRounds++;

                if ($detail) {
                    $details[] = [
                        '轮次开始时间' => $round['round_start_at']->toDateTimeString(),
                        '快照时间' => $snapshotAt->toDateTimeString(),
                        '开始价' => $round['start_price'],
                        '结束价' => $round['end_price'],
                        '实际方向' => $round['actual_direction'],
                        '价差' => $priceDiff,
                        '剩余秒数' => $remainingSeconds,
                        '下单方向' => $side,
                        '入场价' => $entryPrice === '' ? 'null' : $entryPrice,
                        '命中规则' => $matchedRule[0].'/'.$matchedRule[1],
                        '买入份额' => '0',
                        '盈亏' => '0',
                        '结果' => 'skipped',
                    ];
                }

                continue;
            }

            $executedRounds++;
            $totalStake = $this->bcAdd($totalStake, $amount, 8);
            $shares = bcdiv($amount, $entryPrice, 8);
            $isWin = $side === $round['actual_direction'];
            $pnl = $isWin
                ? bcmul($this->bcSub('1', $entryPrice, 8), $shares, 8)
                : $this->bcSub('0', $amount, 8);

            if ($isWin) {
                $winCount++;
            } else {
                $loseCount++;
            }

            $netProfit = $this->bcAdd($netProfit, $pnl, 8);

            if ($detail) {
                $details[] = [
                    '轮次开始时间' => $round['round_start_at']->toDateTimeString(),
                    '快照时间' => $snapshotAt->toDateTimeString(),
                    '开始价' => $round['start_price'],
                    '结束价' => $round['end_price'],
                    '实际方向' => $round['actual_direction'],
                    '价差' => $priceDiff,
                    '剩余秒数' => $remainingSeconds,
                    '下单方向' => $side,
                    '入场价' => $entryPrice,
                    '命中规则' => $matchedRule[0].'/'.$matchedRule[1],
                    '买入份额' => $shares,
                    '盈亏' => $pnl,
                    '结果' => $isWin ? 'win' : 'lose',
                ];
            }
        }

        return [
            'mode' => '2',
            'symbol' => $symbol,
            'window_start' => $startAt->toDateTimeString(),
            'window_end' => $endAt->toDateTimeString(),
            'scanned_snapshots' => $snapshots->count(),
            'valid_rounds' => $roundCount,
            'triggered_rounds' => $triggeredRounds,
            'executed_rounds' => $executedRounds,
            'skipped_rounds' => $skippedRounds,
            'win_count' => $winCount,
            'lose_count' => $loseCount,
            'win_rate' => $executedRounds > 0 ? $this->formatPercent($winCount, $executedRounds) : '0.00%',
            'total_stake' => $totalStake,
            'net_profit' => $netProfit,
            'roi' => bccomp($totalStake, '0', 8) === 1 ? $this->formatBcPercent($netProfit, $totalStake, 4) : '0.00%',
            'details' => $details,
            'message' => null,
        ];
    }

    private function loadMode2Rounds(string $symbol, Carbon $startAt, Carbon $endAt): array
    {
        $roundRows = PmTailSweepRoundOpenPrice::query()
            ->where('symbol', $symbol)
            ->where('round_start_at', '>=', $startAt)
            ->where('round_start_at', '<', $endAt)
            ->whereNotNull('round_open_price')
            ->orderBy('round_start_at')
            ->get(['round_start_at', 'round_end_at', 'round_open_price'])
            ->values();

        $rounds = [];
        for ($i = 0; $i + 1 < $roundRows->count(); $i++) {
            $current = $roundRows[$i];
            $next = $roundRows[$i + 1];
            $startPrice = (string) $current->round_open_price;
            $endPrice = (string) $next->round_open_price;

            if (!$this->isPositiveDecimal($startPrice) || !$this->isPositiveDecimal($endPrice) || !$current->round_end_at) {
                continue;
            }

            $key = (string) optional($current->round_start_at)->timestamp;
            if ($key === '') {
                continue;
            }

            $rounds[] = [
                'key' => $key,
                'round_start_at' => $current->round_start_at,
                'round_end_at' => $current->round_end_at,
                'start_price' => $startPrice,
                'end_price' => $endPrice,
                'actual_direction' => bccomp($endPrice, $startPrice, 8) === 1 ? 'up' : 'down',
            ];
        }

        return $rounds;
    }

    private function loadMode2Snapshots(string $symbol, Carbon $startAt, Carbon $endAt)
    {
        return PmTailSweepMarketSnapshot::query()
            ->where('symbol', $symbol)
            ->where('snapshot_at', '>=', $startAt)
            ->where('snapshot_at', '<', $endAt)
            ->orderBy('snapshot_at')
            ->get([
                'snapshot_at',
                'current_price',
                'up_entry_price5m',
                'down_entry_price5m',
            ]);
    }


    private function printMode1(array $result): void
    {
        $this->line('涨跌口径：开始价格 > 结束价格 记为涨，否则记为跌');
        $this->table(['字段', '值'], [
            ['模式', $result['mode']],
            ['标的', $result['symbol']],
            ['开始时间', $result['window_start']],
            ['结束时间', $result['window_end']],
            ['基础投注', $result['base_bet']],
            ['最小预测价差', '10'],
            ['最大连亏重置限制', $result['max_lose_reset_limit']],
            ['重置次数', $result['reset_lose_count']],
            ['总轮数', $result['total_rounds']],
            ['有效样本', $result['valid_samples']],
            ['赢次数', $result['win_count']],
            ['输次数', $result['lose_count']],
            ['胜率', $result['win_rate']],
            ['总投资金额', $result['total_stake']],
            ['净盈利', $result['net_profit']],
            ['净盈利占比', $result['profit_ratio']],
            ['最大单次投注', $result['max_bet_amount']],
            ['最大连续亏损', $result['max_lose_streak']],
            ['最大资金池', $result['max_funding_need']],
            ['连续亏损分布', $result['lose_streak_summary'] === [] ? '无' : implode(' , ', $result['lose_streak_summary'])],
        ]);

        if ($result['details'] !== []) {
            $this->table(
                ['ID', '开始时间', '上一轮价格', '当前轮价格', '当前预测', '实际方向', '价差绝对值', '投注金额', '结果', '总投资金额', '累计净盈利', '净盈利占比', '连亏次数', '资金占用', '重置次数', '下一轮预测', '下一轮投注'],
                $result['details']
            );
        }
    }

    private function printMode2(array $result): void
    {
        $this->line('涨跌口径：开始价格 > 结束价格 记为涨，否则记为跌');
        if ($result['message']) {
            $this->warn($result['message']);
        }

        $this->table(['字段', '值'], [
            ['模式', $result['mode']],
            ['标的', $result['symbol']],
            ['开始时间', $result['window_start']],
            ['结束时间', $result['window_end']],
            ['每轮最多下单', '1次'],
            ['最小剩余时间', '5秒'],
            ['扫描快照数', $result['scanned_snapshots']],
            ['有效轮次', $result['valid_rounds']],
            ['触发轮次', $result['triggered_rounds']],
            ['成交轮次', $result['executed_rounds']],
            ['跳过轮次', $result['skipped_rounds']],
            ['赢次数', $result['win_count']],
            ['输次数', $result['lose_count']],
            ['胜率', $result['win_rate']],
            ['总投入', $result['total_stake']],
            ['净盈利', $result['net_profit']],
            ['收益率', $result['roi']],
        ]);

        if ($result['details'] !== []) {
            $this->table(
                ['轮次开始时间', '快照时间', '开始价', '结束价', '实际方向', '价差', '剩余秒数', '下单方向', '入场价', '命中规则', '买入份额', '盈亏', '结果'],
                $result['details']
            );
        }
    }

    private function resolveWindow(): array
    {
        $period = (string) $this->argument('period');
        $endAt = now();

        if ($period === 'day') {
            return [$endAt->copy()->subDay(), $endAt];
        }

        if ($period === 'week') {
            return [$endAt->copy()->subWeek(), $endAt];
        }

        if ($period === 'month') {
            return [$endAt->copy()->subMonth(), $endAt];
        }

        throw new \InvalidArgumentException('period 仅支持 day|week|month');
    }

    private function defaultConfig(): array
    {
        return [
            'btc/usd' => [200 => 150, 100 => 120, 30 => 60, 26 => 30],
        ];
    }

    private function isPositiveDecimal(?string $value): bool
    {
        return is_string($value)
            && preg_match('/^\d+(\.\d+)?$/', $value) === 1
            && bccomp($value, '0', 8) === 1;
    }

    private function isValidEntryPrice(?string $value): bool
    {
        return $this->isPositiveDecimal($value) && bccomp($value, '1', 8) === -1;
    }

    private function normalizePositiveDecimal(string $value, int $scale = 8): ?string
    {
        $value = trim($value);
        if (!preg_match('/^\d+(\.\d+)?$/', $value) || bccomp($value, '0', $scale) !== 1) {
            return null;
        }

        return $value;
    }

    private function formatPercent(int $numerator, int $denominator): string
    {
        if ($denominator <= 0) {
            return '0.00%';
        }

        return number_format(($numerator / $denominator) * 100, 2, '.', '').'%';
    }

    private function formatBcPercent(string $numerator, string $denominator, int $scale = 4): string
    {
        if (bccomp($denominator, '0', $scale) !== 1) {
            return '0.00%';
        }

        return bcmul(bcdiv($numerator, $denominator, $scale + 4), '100', 2).'%';
    }

    private function bcAdd(string $left, string $right, int $scale = 8): string
    {
        return bcadd($left, $right, $scale);
    }

    private function bcSub(string $left, string $right, int $scale = 8): string
    {
        return bcsub($left, $right, $scale);
    }

    private function bcAbs(string $value): string
    {
        return str_starts_with($value, '-') ? substr($value, 1) : $value;
    }
}
