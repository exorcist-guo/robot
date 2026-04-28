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
    /**
     * --limit: 控制每个排行榜窗口（week / month）最多同步多少个用户。
     */
    protected $signature = 'pm:sync-leaderboard-stats {--limit=30 : 每个榜单同步前 N 人}';

    /**
     * 命令职责：
     * 1. 先同步 leaderboard 上的用户基础信息；
     * 2. 再按用户地址拉 closed-positions 已平仓记录；
     * 3. 最后基于本地已入库记录重算 day/week/month/all 统计。
     */
    protected $description = '同步排行榜用户、已平仓记录与每日统计';

    /**
     * 命令入口。
     *
     * 这里只同步排行榜用户，再按这些用户去抓 closed positions，
     * 最终统计结果全部基于本地表计算，避免每次统计都重复扫远端接口。
     */
    public function handle(PolymarketDataClient $dataClient): int
    {
        // 先把命令行传入的 limit 收敛到 1~100，避免误传过大值把远端接口打得过重。
        $limit = max(1, min(100, (int) $this->option('limit')));

        // 分别拉取周榜和月榜。
        // 之所以两次拉，是因为有些用户可能只出现在某一个榜单窗口里，
        // 不能只依赖单一窗口，否则会漏人。
        $weekEntries = $this->safeLeaderboardFetch($dataClient, 'week', $limit);
        $monthEntries = $this->safeLeaderboardFetch($dataClient, 'month', $limit);

        // 先把两个窗口的榜单用户合并、标准化并写入本地 users 表。
        // 这一步只负责“有哪些用户、基础名次与资料是什么”，
        // 不负责拉订单明细。
        $users = $this->syncLeaderboardUsers($dataClient, $weekEntries, $monthEntries);
        $this->info('已同步排行榜用户: ' . $users->count());

        foreach ($users as $user) {
            // 逐个用户拉取 closed positions。
            // 这里采用增量模式：命中本地已有记录就停止当前用户继续翻页，
            // 所以多次执行时成本会越来越低。
            $this->syncClosedPositions($dataClient, $user);

            // 用户的已平仓记录更新后，立刻基于本地表重算该用户 day/week/month/all 统计。
            // 这样后续排行直接读本地统计表即可。
            $this->syncDailyStats($user);
        }

        // 全部用户统计更新完后，再统一按本地统计值重排周榜/月榜名次。
        // 这一步是“统计结果 -> 排名结果”的最后收口。
        $this->rebuildRanksFromDailyStats();
        $this->info('排行榜统计同步完成');

        return self::SUCCESS;
    }

    /**
     * @return array<int,array<string,mixed>>
     *
     * 安全拉取排行榜。
     *
     * 如果远端接口临时失败，不让整个命令中断，
     * 而是返回空数组并打印 warning，后续逻辑继续执行。
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
     *
     * 把 week/month 两个榜单的用户先标准化，再合并成一份地址集合。
     *
     * 同一个地址可能只出现在 week，或者只出现在 month，
     * 所以这里不能只依赖某一个窗口，必须把两个窗口的数据合并后再入库。
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
            // 优先取 week，没有再退回 month，作为用户基础资料来源。
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
                // 这次榜单没出现的用户，周/月排名直接清零，避免保留旧排名。
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

    /**
     * 按用户地址抓取 closed positions，并做增量入库。
     *
     * 增量规则：
     * - 首次执行：从 offset=0 开始一路翻页，直到接口返回空或不足一页；
     * - 多次执行：一旦某条 external_position_id 已经存在本地，就立即停止当前用户继续抓取。
     *
     * 这里依赖 closed-positions 按 timestamp DESC 返回，
     * 所以只要命中一条老记录，后面基本都是更旧的数据，不必再继续请求。
     */
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
                // 先把远端 closed position 统一整理成适合本地入库和统计的结构。
                $normalized = $dataClient->normalizeLeaderboardClosedPosition($user->address, $position);
                $exists = PmLeaderboardUserTrade::query()
                    ->where('leaderboard_user_id', $user->id)
                    ->where('external_position_id', $normalized['external_position_id'])
                    ->exists();

                if ($exists) {
                    // 命中本地已有记录时，说明当前用户更旧的数据已经抓过，直接停止当前用户翻页。
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

    /**
     * 基于本地已平仓记录重算 day/week/month/all 四个统计窗口。
     *
     * 这里不再依赖远端实时统计，而是直接用 pm_leaderboard_user_trades 表里的字段：
     * - invested_amount_usdc
     * - pnl_amount_usdc
     * - profit_amount_usdc
     * - loss_amount_usdc
     * - is_win
     *
     * 这样后续展示、排行、回溯都只依赖本地数据。
     */
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
            // closed-positions 表里每一条都是已结束记录，所以这里 total_orders 就等于 closedCount。
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

    /**
     * 用当天生成的 daily stats 重建周榜/月榜排序。
     *
     * 排序依据不是直接信任远端返回名次，
     * 而是基于本地统计字段重新排序，保证 week/month 口径一致且可回放。
     */
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
