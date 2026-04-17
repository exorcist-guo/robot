<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PmOptimizeTailSweepCommand extends Command
{
    protected $signature = 'pm:optimize-tail-sweep
        {period : day|week|month}
        {--symbol=btc/usd : 默认标的}
        {--amount=20 : 模式二买入金额，默认 20 USDC}
        {--config-set= : 多组配置，分号分隔；单组格式如 200:150,100:120,30:60,26:30}
        {--diffs= : 自动生成配置的价差候选，组间用 | 分隔，组内用 , 分隔}
        {--times= : 自动生成配置的时间候选，组间用 | 分隔，组内用 , 分隔}
        {--top=20 : 输出前 N 名}
        {--min-executed=1 : 过滤最小成交轮次}';

    protected $description = '批量回测模式二配置并找出收益更优的参数组合';

    public function handle(PmBacktestTailSweepCommand $backtestCommand): int
    {
        $period = (string) $this->argument('period');
        if (!in_array($period, ['day', 'week', 'month'], true)) {
            $this->error('period 仅支持 day|week|month');

            return self::FAILURE;
        }

        $symbol = trim((string) $this->option('symbol')) ?: 'btc/usd';
        $amount = trim((string) $this->option('amount'));
        if (!$this->isPositiveDecimal($amount, 6)) {
            $this->error('amount 必须是大于 0 的数字');

            return self::FAILURE;
        }

        $top = max(1, (int) $this->option('top'));
        $minExecuted = max(1, (int) $this->option('min-executed'));
        $rawConfigSet = trim((string) $this->option('config-set'));
        $rawDiffs = trim((string) $this->option('diffs'));
        $rawTimes = trim((string) $this->option('times'));

        if ($rawConfigSet === '' && ($rawDiffs === '' || $rawTimes === '')) {
            $this->error('请提供 config-set，或同时提供 diffs 和 times');

            return self::FAILURE;
        }

        if (($rawDiffs === '') !== ($rawTimes === '')) {
            $this->error('diffs 和 times 必须同时提供');

            return self::FAILURE;
        }

        [$startAt, $endAt] = $this->resolveWindow($period);
        $candidates = $rawConfigSet !== ''
            ? $this->parseConfigSet($rawConfigSet)
            : $this->buildCandidatesFromRanges($rawDiffs, $rawTimes);
        if ($candidates === []) {
            $this->error('没有可用的配置组');

            return self::FAILURE;
        }

        $results = [];
        $totalCandidates = count($candidates);
        $bestResult = null;
        foreach ($candidates as $index => $candidate) {
            $prefix = '['.($index + 1).'/'.$totalCandidates.'] ';

            if (!$candidate['valid']) {
                $this->warn($prefix.'跳过配置 '.$candidate['raw'].'：'.$candidate['error']);
                continue;
            }

            $result = $backtestCommand->backtestMode2($symbol, $startAt, $endAt, $amount, $candidate['config'], false);

            if (($result['executed_rounds'] ?? 0) < $minExecuted) {
                continue;
            }

            $current = [
                'config' => $candidate['normalized'],
                'net_profit' => (string) $result['net_profit'],
                'roi' => (string) $result['roi'],
                'executed_rounds' => (int) $result['executed_rounds'],
                'win_rate' => (string) $result['win_rate'],
                'skipped_rounds' => (int) $result['skipped_rounds'],
                'triggered_rounds' => (int) $result['triggered_rounds'],
            ];

            $results[] = $current;

            if ($bestResult === null || $this->compareNumericString($current['net_profit'], $bestResult['net_profit']) === 1) {
                $bestResult = $current;
                $this->line($prefix.'发现新的最优配置 '.$current['config'].' => 净盈利='.$current['net_profit'].' 收益率='.$current['roi'].' 成交轮次='.$current['executed_rounds'].' 胜率='.$current['win_rate'].' 跳过轮次='.$current['skipped_rounds'].' 触发轮次='.$current['triggered_rounds']);
            }
        }

        usort($results, function (array $left, array $right): int {
            return $this->compareNumericString((string) $right['net_profit'], (string) $left['net_profit']);
        });

        $ranked = array_slice($results, 0, $top);

        $this->table(['字段', '值'], [
            ['周期', $period],
            ['标的', $symbol],
            ['买入金额', $amount],
            ['最优标准', '净盈利最大'],
            ['最小成交轮次', (string) $minExecuted],
            ['候选配置数', (string) count($candidates)],
            ['入榜配置数', (string) count($ranked)],
        ]);

        if ($ranked === []) {
            $this->warn('没有配置满足最小成交轮次条件');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($ranked as $index => $item) {
            $rows[] = [
                '排名' => $index + 1,
                '配置' => $item['config'],
                '净盈利' => $item['net_profit'],
                '收益率' => $item['roi'],
                '成交轮次' => $item['executed_rounds'],
                '胜率' => $item['win_rate'],
                '跳过轮次' => $item['skipped_rounds'],
                '触发轮次' => $item['triggered_rounds'],
            ];
        }

        $this->table(['排名', '配置', '净盈利', '收益率', '成交轮次', '胜率', '跳过轮次', '触发轮次'], $rows);

        return self::SUCCESS;
    }

    private function resolveWindow(string $period): array
    {
        $endAt = now();

        if ($period === 'day') {
            return [$endAt->copy()->subDay(), $endAt];
        }

        if ($period === 'week') {
            return [$endAt->copy()->subWeek(), $endAt];
        }

        return [$endAt->copy()->subMonth(), $endAt];
    }

    private function buildCandidatesFromRanges(string $rawDiffs, string $rawTimes): array
    {
        $diffGroups = $this->parseRangeGroups($rawDiffs, 'diffs');
        $timeGroups = $this->parseRangeGroups($rawTimes, 'times');

        if (!$diffGroups['valid']) {
            return [[
                'raw' => $rawDiffs,
                'valid' => false,
                'error' => $diffGroups['error'],
            ]];
        }

        if (!$timeGroups['valid']) {
            return [[
                'raw' => $rawTimes,
                'valid' => false,
                'error' => $timeGroups['error'],
            ]];
        }

        if (count($diffGroups['groups']) !== count($timeGroups['groups'])) {
            return [[
                'raw' => $rawDiffs.' | '.$rawTimes,
                'valid' => false,
                'error' => 'diffs 和 times 的分组数量必须一致',
            ]];
        }

        $results = [];
        $this->expandRangeGroups($diffGroups['groups'], $timeGroups['groups'], 0, [], $results);

        return $results;
    }

    private function parseRangeGroups(string $raw, string $field): array
    {
        $groups = array_filter(array_map('trim', explode('|', $raw)), static fn ($item) => $item !== '');
        if ($groups === []) {
            return ['valid' => false, 'error' => $field.' 不能为空'];
        }

        $parsedGroups = [];
        foreach ($groups as $group) {
            $values = array_filter(array_map('trim', explode(',', $group)), static fn ($item) => $item !== '');
            if ($values === []) {
                return ['valid' => false, 'error' => $field.' 存在空分组'];
            }

            $parsed = [];
            $lastValue = null;
            foreach ($values as $value) {
                if (!$this->isPositiveInt($value)) {
                    return ['valid' => false, 'error' => $field.' 只能包含正整数'];
                }

                $intValue = (int) $value;
                if ($lastValue !== null && $intValue >= $lastValue) {
                    return ['valid' => false, 'error' => $field.' 每组必须从大到小'];
                }

                $parsed[] = $intValue;
                $lastValue = $intValue;
            }

            $parsedGroups[] = $parsed;
        }

        return ['valid' => true, 'groups' => $parsedGroups];
    }

    private function expandRangeGroups(array $diffGroups, array $timeGroups, int $index, array $current, array &$results): void
    {
        if ($index >= count($diffGroups)) {
            $config = [];
            foreach ($current as [$threshold, $timeLimit]) {
                $config[$threshold] = $timeLimit;
            }

            $results[] = [
                'raw' => $this->normalizeConfigString($config),
                'valid' => true,
                'config' => $config,
                'normalized' => $this->normalizeConfigString($config),
            ];

            return;
        }

        foreach ($diffGroups[$index] as $threshold) {
            foreach ($timeGroups[$index] as $timeLimit) {
                if ($current !== []) {
                    [$prevThreshold, $prevTimeLimit] = $current[count($current) - 1];
                    if ($threshold >= $prevThreshold || $timeLimit >= $prevTimeLimit) {
                        continue;
                    }
                }

                $next = $current;
                $next[] = [$threshold, $timeLimit];
                $this->expandRangeGroups($diffGroups, $timeGroups, $index + 1, $next, $results);
            }
        }
    }

    private function parseSingleConfig(string $raw): array
    {
        $pairs = array_filter(array_map('trim', explode(',', $raw)), static fn ($item) => $item !== '');
        if ($pairs === []) {
            return ['raw' => $raw, 'valid' => false, 'error' => '空配置'];
        }

        $config = [];
        $lastThreshold = null;
        $lastTimeLimit = null;

        foreach ($pairs as $pair) {
            $parts = array_map('trim', explode(':', $pair));
            if (count($parts) !== 2 || !$this->isPositiveInt($parts[0]) || !$this->isPositiveInt($parts[1])) {
                return ['raw' => $raw, 'valid' => false, 'error' => '格式错误，需使用 threshold:time'];
            }

            $threshold = (int) $parts[0];
            $timeLimit = (int) $parts[1];

            if (isset($config[$threshold])) {
                return ['raw' => $raw, 'valid' => false, 'error' => '价差阈值重复'];
            }

            if ($lastThreshold !== null && $threshold >= $lastThreshold) {
                return ['raw' => $raw, 'valid' => false, 'error' => '价差阈值需从大到小'];
            }

            if ($lastTimeLimit !== null && $timeLimit >= $lastTimeLimit) {
                return ['raw' => $raw, 'valid' => false, 'error' => '时间阈值需从大到小'];
            }

            $config[$threshold] = $timeLimit;
            $lastThreshold = $threshold;
            $lastTimeLimit = $timeLimit;
        }

        return [
            'raw' => $raw,
            'valid' => true,
            'config' => $config,
            'normalized' => $this->normalizeConfigString($config),
        ];
    }

    private function normalizeConfigString(array $config): string
    {
        $parts = [];
        foreach ($config as $threshold => $timeLimit) {
            $parts[] = $threshold.':'.$timeLimit;
        }

        return implode(',', $parts);
    }

    private function isPositiveDecimal(string $value, int $scale = 8): bool
    {
        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 && bccomp($value, '0', $scale) === 1;
    }

    private function isPositiveInt(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1 && (int) $value > 0;
    }

    private function compareNumericString(string $left, string $right): int
    {
        $normalizedLeft = rtrim($left, '%');
        $normalizedRight = rtrim($right, '%');

        if (preg_match('/^-?\d+(\.\d+)?$/', $normalizedLeft) === 1 && preg_match('/^-?\d+(\.\d+)?$/', $normalizedRight) === 1) {
            return bccomp($normalizedLeft, $normalizedRight, 8);
        }

        return $left <=> $right;
    }
}
