<?php

namespace App\Services\Pm;

use App\Models\Pm\PmSkipRoundStrategy;
use App\Models\Pm\PmSkipRoundStrategyLine;

class SkipRoundLineStateService
{
    /**
     * @param array<string,mixed> $config
     * @return array{strategy:PmSkipRoundStrategy,line:PmSkipRoundStrategyLine}
     */
    public function bootstrap(array $config): array
    {
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

        foreach (['A', 'B'] as $lineCode) {
            PmSkipRoundStrategyLine::firstOrCreate(
                ['strategy_id' => $strategy->id, 'line_code' => $lineCode],
                ['current_bet_amount' => (string) $config['base_bet']]
            );
        }

        $line = $strategy->lines()->where('line_code', $strategy->next_line)->firstOrFail();

        return ['strategy' => $strategy->fresh('lines'), 'line' => $line];
    }

    public function rotate(PmSkipRoundStrategy $strategy): void
    {
        $strategy->next_line = $strategy->next_line === 'A' ? 'B' : 'A';
        $strategy->save();
    }

    /**
     * @param array<string,mixed> $config
     */
    public function settle(PmSkipRoundStrategyLine $line, PmSkipRoundStrategy $strategy, array $config, string $targetRoundKey, string $result, int $orderId): void
    {
        $baseBet = (string) $config['base_bet'];
        $limit = (int) $config['max_lose_reset_limit'];

        $line->total_bet_count++;
        $line->last_bet_round_key = $targetRoundKey;
        $line->last_settled_round_key = $targetRoundKey;
        $line->last_order_id = $orderId;
        $line->last_result = $result;

        if ($result === 'win') {
            $line->total_win_count++;
            $line->lose_streak_count = 0;
            $line->current_bet_amount = $baseBet;
        } else {
            $line->total_lose_count++;
            $line->lose_streak_count++;
            if ($line->lose_streak_count >= $limit) {
                $line->lose_streak_count = 0;
                $line->current_bet_amount = $baseBet;
                $line->last_result = 'reset';
            } else {
                $line->current_bet_amount = bcmul((string) $line->current_bet_amount, '2', 8);
            }
        }

        $line->save();
        $strategy->save();
    }
}
