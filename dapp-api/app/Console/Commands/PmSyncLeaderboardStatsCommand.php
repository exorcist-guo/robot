<?php

namespace App\Console\Commands;

use App\Models\Pm\PmLeaderboardDailyStat;
use App\Models\Pm\PmLeaderboardUser;
use App\Models\Pm\PmLeaderboardUserTrade;
use App\Services\Pm\PolymarketDataClient;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PmSyncLeaderboardStatsCommand extends Command
{
    protected $signature = 'pm:sync-leaderboard-stats {--limit=30 : 每个榜单同步前 N 人}';

    protected $description = '同步排行榜用户、用户近30天成交记录与每日统计';

    public function handle(PolymarketDataClient $dataClient): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));

        $weekEntries = $this->safeLeaderboardFetch($dataClient, 'week', $limit);
        $monthEntries = $this->safeLeaderboardFetch($dataClient, 'month', $limit);

        $users = $this->syncLeaderboardUsers($dataClient, $weekEntries, $monthEntries);
        $this->info('已同步排行榜用户: ' . $users->count());

        foreach ($users as $user) {
            $this->syncUserTrades($dataClient, $user);
            $this->syncUnsettledTrades($dataClient, $user);
            $this->syncDailyStats($user);
        }

        $this->info('排行榜统计同步完成');
        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function safeLeaderboardFetch(PolymarketDataClient $dataClient, string $window, int $limit): array
    {
        try {
            return $dataClient->getLeaderboard($window, $limit);
        } catch (\Throwable $e) {
            $this->warn("拉取 {$window} 榜失败: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * @param array<int,array<string,mixed>> $weekEntries
     * @param array<int,array<string,mixed>> $monthEntries
     * @return \Illuminate\Support\Collection<int,PmLeaderboardUser>
     */
    private function syncLeaderboardUsers(PolymarketDataClient $dataClient, array $weekEntries, array $monthEntries)
    {
        $weekMap = [];
        foreach ($weekEntries as $entry) {
            $normalized = $dataClient->normalizeLeaderboardEntry($entry, 'week');
            if ($normalized['address'] !== '') {
                $weekMap[$normalized['address']] = $normalized;
            }
        }

        $monthMap = [];
        foreach ($monthEntries as $entry) {
            $normalized = $dataClient->normalizeLeaderboardEntry($entry, 'month');
            if ($normalized['address'] !== '') {
                $monthMap[$normalized['address']] = $normalized;
            }
        }

        $addresses = array_values(array_unique(array_merge(array_keys($weekMap), array_keys($monthMap))));
        $now = now();

        foreach ($addresses as $address) {
            $week = $weekMap[$address] ?? null;
            $month = $monthMap[$address] ?? null;
            $base = $week ?? $month;

            PmLeaderboardUser::updateOrCreate(
                ['address' => $address],
                [
                    'proxy_wallet' => $base['proxy_wallet'] ?: $address,
                    'username' => $base['username'] ?: null,
                    'x_username' => $base['x_username'] ?: null,
                    'profile_image' => $base['profile_image'] ?: null,
                    'verified_badge' => (bool) ($base['verified_badge'] ?? false),
                    'week_rank' => (int) ($week['rank'] ?? 0),
                    'month_rank' => (int) ($month['rank'] ?? 0),
                    'week_volume' => $week['volume'] ?? null,
                    'month_volume' => $month['volume'] ?? null,
                    'week_pnl' => $week['pnl'] ?? null,
                    'month_pnl' => $month['pnl'] ?? null,
                    'last_ranked_at' => $now,
                    'raw' => [
                        'week' => $week['raw'] ?? null,
                        'month' => $month['raw'] ?? null,
                    ],
                ]
            );
        }

        PmLeaderboardUser::query()
            ->whereNotIn('address', $addresses)
            ->update([
                'week_rank' => 0,
                'month_rank' => 0,
            ]);

        return PmLeaderboardUser::query()
            ->where(function ($query) {
                $query->where('week_rank', '>', 0)
                    ->orWhere('month_rank', '>', 0);
            })
            ->orderByDesc('week_rank')
            ->orderByDesc('month_rank')
            ->get();
    }

    private function syncUserTrades(PolymarketDataClient $dataClient, PmLeaderboardUser $user): void
    {
        $offset = 0;
        $limit = 100;

        while (true) {
            if ($offset > 3000) {
                return;
            }

            $items = $dataClient->getTradesByUserInLastDays($user->address, 30, $limit, $offset);
            if ($items === []) {
                break;
            }

            foreach ($items as $trade) {
                $normalized = $dataClient->normalizeLeaderboardTrade($user->address, $trade);
                $exists = PmLeaderboardUserTrade::query()
                    ->where('leaderboard_user_id', $user->id)
                    ->where('external_trade_id', $normalized['external_trade_id'])
                    ->exists();

                if ($exists) {
                    return;
                }

                PmLeaderboardUserTrade::create([
                    'leaderboard_user_id' => $user->id,
                    'address' => $user->address,
                ] + $normalized);
            }

            if (count($items) < $limit) {
                break;
            }

            $offset += $limit;
        }
    }

    private function syncUnsettledTrades(PolymarketDataClient $dataClient, PmLeaderboardUser $user): void
    {
        $trades = PmLeaderboardUserTrade::query()
            ->where('leaderboard_user_id', $user->id)
            ->where(function ($query) {
                $query->where('is_settled', false)
                    ->orWhereNull('pnl_amount_usdc');
            })
            ->orderBy('id')
            ->get();

        if ($trades->isEmpty()) {
            return;
        }

        $grouped = $trades->groupBy('market_id');
        foreach ($grouped as $marketId => $items) {
            $marketId = trim((string) $marketId);
            if ($marketId === '') {
                continue;
            }

            try {
                $marketPositions = $dataClient->getMarketPositions($marketId, $user->address);
            } catch (\Throwable) {
                continue;
            }

            $byAsset = [];
            $byOutcome = [];
            foreach ($marketPositions as $bucket) {
                if (!is_array($bucket)) {
                    continue;
                }
                $positions = $bucket['positions'] ?? [];
                if (!is_array($positions)) {
                    continue;
                }
                foreach ($positions as $position) {
                    if (!is_array($position)) {
                        continue;
                    }
                    $asset = strtolower((string) ($position['asset'] ?? ''));
                    $outcome = strtolower(trim((string) ($position['outcome'] ?? '')));
                    if ($asset !== '') {
                        $byAsset[$asset] = $position;
                    }
                    if ($outcome !== '') {
                        $byOutcome[$outcome] = $position;
                    }
                }
            }

            foreach ($items as $trade) {
                $assetKey = strtolower((string) ($trade->token_id ?? ''));
                $outcomeKey = strtolower(trim((string) ($trade->outcome ?? '')));
                $position = $byAsset[$assetKey] ?? $byOutcome[$outcomeKey] ?? null;
                if (!$position) {
                    continue;
                }

                $trade->pnl_amount_usdc = $this->toUsdcAtomicFromDecimal($position['totalPnl'] ?? $position['realizedPnl'] ?? $position['cashPnl'] ?? null);
                $trade->pnl_ratio_bps = $this->toBpsFromPercent($position['percentPnl'] ?? $position['percentRealizedPnl'] ?? null);
                $size = (float) ($position['size'] ?? 0);
                $currentValue = (float) ($position['currentValue'] ?? 0);
                $trade->is_settled = ((bool) ($position['redeemable'] ?? false)) || ($size <= 0.0 && $currentValue <= 0.0);
                $trade->order_status = $trade->is_settled ? 'settled' : 'open';
                $trade->pnl_status = $trade->pnl_amount_usdc === null
                    ? ($trade->is_settled ? 'settled' : 'pending')
                    : ($trade->pnl_amount_usdc > 0 ? 'profit' : ($trade->pnl_amount_usdc < 0 ? 'loss' : 'flat'));
                $trade->settled_at = $trade->is_settled ? now() : null;
                $trade->last_synced_at = now();
                $trade->raw = array_merge(is_array($trade->raw) ? $trade->raw : [], [
                    'market_position_snapshot' => $position,
                ]);
                $trade->save();
            }
        }
    }

    private function syncDailyStats(PmLeaderboardUser $user): void
    {
        $today = Carbon::today();
        $trades = PmLeaderboardUserTrade::query()
            ->where('leaderboard_user_id', $user->id)
            ->get();

        $scopes = [
            'day' => $trades->filter(fn (PmLeaderboardUserTrade $trade) => $trade->traded_at && $trade->traded_at->greaterThanOrEqualTo($today)),
            'week' => $trades->filter(fn (PmLeaderboardUserTrade $trade) => $trade->traded_at && $trade->traded_at->greaterThanOrEqualTo($today->copy()->startOfWeek())),
            'month' => $trades->filter(fn (PmLeaderboardUserTrade $trade) => $trade->traded_at && $trade->traded_at->greaterThanOrEqualTo($today->copy()->startOfMonth())),
            'all' => $trades,
        ];

        $payload = [];
        $raw = [];
        foreach ($scopes as $scope => $items) {
            $totalOrders = $items->count();
            $closed = $items->filter(function (PmLeaderboardUserTrade $trade) {
                return $trade->pnl_amount_usdc !== null;
            });
            $winOrders = $closed->filter(function (PmLeaderboardUserTrade $trade) {
                return (int) $trade->pnl_amount_usdc > 0;
            })->count();
            $lossOrders = $closed->filter(function (PmLeaderboardUserTrade $trade) {
                return (int) $trade->pnl_amount_usdc < 0;
            })->count();
            $investedAmount = (int) $items->sum('invested_amount_usdc');
            $profitAmount = (int) $closed->sum('pnl_amount_usdc');
            $closedCount = $closed->count();
            $winRateBps = $closedCount > 0 ? (int) floor(($winOrders / $closedCount) * 10000) : 0;

            $payload[$scope . '_total_orders'] = $totalOrders;
            $payload[$scope . '_win_orders'] = $winOrders;
            $payload[$scope . '_loss_orders'] = $lossOrders;
            $payload[$scope . '_win_rate_bps'] = $winRateBps;
            $payload[$scope . '_invested_amount_usdc'] = $investedAmount;
            $payload[$scope . '_profit_amount_usdc'] = $profitAmount;
            $raw[$scope] = [
                'total_orders' => $totalOrders,
                'closed_orders' => $closedCount,
                'win_orders' => $winOrders,
                'loss_orders' => $lossOrders,
                'win_rate_bps' => $winRateBps,
                'invested_amount_usdc' => $investedAmount,
                'profit_amount_usdc' => $profitAmount,
            ];
        }

        PmLeaderboardDailyStat::updateOrCreate(
            [
                'leaderboard_user_id' => $user->id,
                'stat_date' => $today->toDateString(),
            ],
            $payload + ['raw' => $raw]
        );
    }

    private function toUsdcAtomicFromDecimal(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = (string) $value;
        if (!preg_match('/^-?\d+(\.\d+)?$/', $string)) {
            return null;
        }

        return (int) floor((float) $string * 1000000);
    }

    private function toBpsFromPercent(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = (string) $value;
        if (!preg_match('/^-?\d+(\.\d+)?$/', $string)) {
            return null;
        }

        return (int) round((float) $string * 100);
    }
}
