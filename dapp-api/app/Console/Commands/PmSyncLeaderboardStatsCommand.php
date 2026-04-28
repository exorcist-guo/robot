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

    protected $description = '同步排行榜用户、已平仓记录与每日统计';

    public function handle(PolymarketDataClient $dataClient): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));

        $weekEntries = $this->safeLeaderboardFetch($dataClient, 'week', $limit);
        $monthEntries = $this->safeLeaderboardFetch($dataClient, 'month', $limit);

        $users = $this->syncLeaderboardUsers($dataClient, $weekEntries, $monthEntries);
        $this->info('已同步排行榜用户: ' . $users->count());

        foreach ($users as $user) {
            $this->syncClosedPositions($dataClient, $user);
            $this->syncDailyStats($user);
        }

        $this->rebuildRanksFromDailyStats();
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

    private function syncClosedPositions(PolymarketDataClient $dataClient, PmLeaderboardUser $user): void
    {
        $offset = 0;
        $limit = 30;

        while (true) {
            if ($offset > 3000) {
                return;
            }

            try {
                $items = $dataClient->getClosedPositionsByUser($user->address, $limit, $offset);
            } catch (\Throwable $e) {
                $this->warn("拉取 {$user->address} closed positions 失败: {$e->getMessage()}");
                return;
            }

            if ($items === []) {
                break;
            }

            foreach ($items as $position) {
                $normalized = $dataClient->normalizeLeaderboardClosedPosition($user->address, $position);
                $exists = PmLeaderboardUserTrade::query()
                    ->where('leaderboard_user_id', $user->id)
                    ->where('external_position_id', $normalized['external_position_id'])
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
            $winOrders = $items->filter(fn (PmLeaderboardUserTrade $trade) => $trade->is_win === true)->count();
            $lossOrders = $items->filter(fn (PmLeaderboardUserTrade $trade) => (int) $trade->loss_amount_usdc > 0)->count();
            $investedAmount = (int) $items->sum('invested_amount_usdc');
            $profitAmount = (int) $items->sum('pnl_amount_usdc');
            $winAmount = (int) $items->sum('profit_amount_usdc');
            $lossAmount = (int) $items->sum('loss_amount_usdc');
            $closedCount = $totalOrders;
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
                'win_amount_usdc' => $winAmount,
                'loss_amount_usdc' => $lossAmount,
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

    private function rebuildRanksFromDailyStats(): void
    {
        $today = Carbon::today();
        $latestStats = PmLeaderboardDailyStat::query()
            ->where('stat_date', $today->toDateString())
            ->get()
            ->keyBy('leaderboard_user_id');

        $weekSorted = $latestStats->sortByDesc(fn (PmLeaderboardDailyStat $stat) => [
            (int) $stat->week_profit_amount_usdc,
            (int) $stat->week_win_rate_bps,
            (int) $stat->week_total_orders,
        ])->values();

        foreach ($weekSorted as $index => $stat) {
            PmLeaderboardUser::query()
                ->whereKey($stat->leaderboard_user_id)
                ->update(['week_rank' => $index + 1]);
        }

        $monthSorted = $latestStats->sortByDesc(fn (PmLeaderboardDailyStat $stat) => [
            (int) $stat->month_profit_amount_usdc,
            (int) $stat->month_win_rate_bps,
            (int) $stat->month_total_orders,
        ])->values();

        foreach ($monthSorted as $index => $stat) {
            PmLeaderboardUser::query()
                ->whereKey($stat->leaderboard_user_id)
                ->update(['month_rank' => $index + 1]);
        }
    }
}
