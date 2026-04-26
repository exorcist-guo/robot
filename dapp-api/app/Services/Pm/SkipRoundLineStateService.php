<?php

namespace App\Services\Pm;

use App\Models\Pm\PmSkipRoundStrategy;
use App\Models\Pm\PmSkipRoundStrategyLine;

class SkipRoundLineStateService
{
    /**
     * 初始化隔一轮策略及其 A/B 两条资金线。
     *
     * 作用：
     * 1. 第一次运行时创建策略主记录；
     * 2. 第一次运行时创建 A/B 两条线；
     * 3. 返回当前应该使用的那条线（由 strategy.next_line 指定）。
     *
     * @param array<string,mixed> $config
     * @return array{strategy:PmSkipRoundStrategy,line:PmSkipRoundStrategyLine}
     */
    public function bootstrap(array $config): array
    {
        // strategy_key 是整套策略的唯一标识，固定后每次启动都会落到同一条策略记录上。
        $strategy = PmSkipRoundStrategy::firstOrCreate(
            ['strategy_key' => (string) $config['strategy_key']],
            [
                'strategy_name' => (string) $config['strategy_name'],
                'member_id' => (int) $config['member_id'],
                'market_slug' => (string) $config['market_slug'],
                'resolution_source' => (string) ($config['resolution_source'] ?? ''),
                'symbol' => (string) ($config['symbol'] ?? 'btc/usd'),
                'base_bet_amount' => (string) $config['base_bet'],
                'max_lose_reset_limit' => (int) $config['max_lose_reset_limit'],
                'min_predict_diff' => (string) $config['min_predict_diff'],
                'next_line' => 'A',
                'status' => 1,
                'config_snapshot' => $config,
            ]
        );

        // A/B 两条线是独立的资金状态机：
        // - 每次触发只使用其中一条线
        // - 下一次触发切到另一条线
        // - 每条线各自维护自己的当前下注金额、连亏次数、历史输赢统计
        foreach (['A', 'B'] as $lineCode) {
            PmSkipRoundStrategyLine::firstOrCreate(
                ['strategy_id' => $strategy->id, 'line_code' => $lineCode],
                ['current_bet_amount' => (string) $config['base_bet']]
            );
        }

        // 读取当前应出手的那条线。
        $line = $strategy->lines()->where('line_code', $strategy->next_line)->firstOrFail();

        return ['strategy' => $strategy->fresh('lines'), 'line' => $line];
    }

    /**
     * A/B 线轮换：A -> B，B -> A。
     *
     * 每成功创建一笔订单后，策略会切到另一条线，
     * 保证两条线交替下注，而不是连续用同一条线。
     */
    public function rotate(PmSkipRoundStrategy $strategy): void
    {
        $strategy->next_line = $strategy->next_line === 'A' ? 'B' : 'A';
        $strategy->save();
    }

    /**
     * 根据订单结算结果，推进单条资金线状态。
     *
     * 规则：
     * - win：当前线下注金额重置为 base_bet，连亏清零
     * - lose：当前线下注金额翻倍，连亏 +1
     * - 若连亏达到 max_lose_reset_limit：金额重置，连亏清零，并把 last_result 记为 reset
     *
     * @param array<string,mixed> $config
     */
    public function settle(PmSkipRoundStrategyLine $line, PmSkipRoundStrategy $strategy, array $config, string $targetRoundKey, string $result, int $orderId): void
    {
        $baseBet = (string) $config['base_bet'];
        $limit = (int) $config['max_lose_reset_limit'];

        // 先更新这条线的基础结算信息。
        $line->total_bet_count++;
        $line->last_bet_round_key = $targetRoundKey;
        $line->last_settled_round_key = $targetRoundKey;
        $line->last_order_id = $orderId;
        $line->last_result = $result;

        if ($result === 'win') {
            // 赢了：
            // - 胜场数 +1
            // - 连亏清零
            // - 下一次下注金额恢复到初始金额
            $line->total_win_count++;
            $line->lose_streak_count = 0;
            $line->current_bet_amount = $baseBet;
        } else {
            // 输了：
            // - 败场数 +1
            // - 连亏次数 +1
            $line->total_lose_count++;
            $line->lose_streak_count++;
            if ($line->lose_streak_count >= $limit) {
                // 连续亏损达到上限：
                // - 视为触发重置
                // - 金额恢复初始值
                // - 连亏清零
                $line->lose_streak_count = 0;
                $line->current_bet_amount = $baseBet;
                $line->last_result = 'reset';
            } else {
                // 未达到重置上限：下一次下注金额翻倍。
                $line->current_bet_amount = bcmul((string) $line->current_bet_amount, '2', 8);
            }
        }

        $line->save();

        // 当前 settle 只推进资金线状态。
        // strategy 主记录这里暂时没有额外派生字段需要更新，但保留 save 方便后续扩展。
        $strategy->save();
    }
}
