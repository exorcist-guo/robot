<?php

namespace App\Console\Commands;

use App\Jobs\PmCreateOrderIntentsJob;
use App\Models\Pm\PmLeader;
use App\Models\Pm\PmLeaderTrade;
use App\Services\Pm\Contracts\LeaderTradeSourceInterface;
use App\Services\Pm\Sources\DataApiLeaderTradeSource;
use App\Services\Pm\Sources\FastApiLeaderTradeSource;
use Illuminate\Console\Command;

class PmPollLeaderTradesCommand extends Command
{
    /**
     * --once: 只拉取并处理一轮 leader 成交，便于手动调试。
     */
    protected $signature = 'pm:poll-leader-trades {--once : 仅执行一次，不循环}';

    /**
     * 这个命令负责持续轮询 leader 的成交记录，
     * 把最新成交写入 pm_leader_trades，
     * 并为新增且足够新的成交派发跟单意图生成任务。
     */
    protected $description = '轮询 leader 的 Polymarket 成交并生成跟单意图';

    /**
     * 命令入口。
     *
     * 支持三种来源模式：
     * 1. data_api  : 走 Polymarket data api
     * 2. fastapi   : 走内部 fastapi
     * 3. dual_run  : 两边同时拉取，对比结果，但实际只落 data_api 的结果
     *
     * 默认每 5 秒轮询一次；传 --once 时只执行一轮。
     */
    public function handle(DataApiLeaderTradeSource $dataSource, FastApiLeaderTradeSource $fastApiSource): int
    {
        $once = (bool) $this->option('once');
        $mode = (string) config('dapp_py.leader_trade_mode', 'data_api');

        do {
            if ($mode === 'dual_run') {
                // 双跑模式：同时请求两个数据源，用来观察 trade_id 是否一致、是否有漏单。
                $this->pollDualRun($dataSource, $fastApiSource);
            } else {
                // 普通模式：根据配置选择一个数据源直接拉取并入库。
                $this->poll($this->resolveSource($mode, $dataSource, $fastApiSource), $mode);
            }
            if ($once) {
                $this->info('已按 --once 执行单次轮询');
                break;
            }
            sleep(5);
        } while (true);

        return self::SUCCESS;
    }

    /**
     * 使用指定数据源轮询所有启用中的 leader。
     */
    private function poll(LeaderTradeSourceInterface $source, string $mode): void
    {
        // 只处理状态为启用(status=1)的 leader。
        $leaders = PmLeader::where('status', 1)->get();
        foreach ($leaders as $leader) {
            try {
                // 每次只取最近 10 条成交，offset=0 表示从最新开始。
                $trades = $source->fetchTradesByUser($leader->proxy_wallet, 10, 0);
            } catch (\Throwable $e) {
                $this->error("leader {$leader->id} 拉取 trades 失败[{$mode}]: {$e->getMessage()}");
                continue;
            }

            $this->persistTrades($leader, $trades, $mode);
        }
    }

    /**
     * 双跑模式。
     *
     * 同时从 data_api 和 fastapi 拉取相同 leader 的最新成交，
     * 对比两边返回的 trade_id 差异，便于检查漏单、延迟或兼容问题。
     *
     * 注意：这里只打印对比结果，真正写库仍然只使用 data_api 返回的数据。
     */
    private function pollDualRun(DataApiLeaderTradeSource $dataSource, FastApiLeaderTradeSource $fastApiSource): void
    {
        $leaders = PmLeader::where('status', 1)->get();
        foreach ($leaders as $leader) {
            try {
                $primaryTrades = $dataSource->fetchTradesByUser($leader->proxy_wallet, 10, 0);
                $shadowTrades = $fastApiSource->fetchTradesByUser($leader->proxy_wallet, 10, 0);
            } catch (\Throwable $e) {
                $this->error("leader {$leader->id} 双跑失败[dual_run]: {$e->getMessage()}");
                continue;
            }

            // 提取两边的 trade_id 列表，后面做集合差集比较。
            $primaryIds = collect($primaryTrades)->pluck('trade_id')->filter()->values()->all();
            $shadowIds = collect($shadowTrades)->pluck('trade_id')->filter()->values()->all();
            $onlyPrimary = array_values(array_diff($primaryIds, $shadowIds));
            $onlyShadow = array_values(array_diff($shadowIds, $primaryIds));

            // 输出双跑对比结果，方便直接观察两边是否返回一致。
            $this->line(json_encode([
                'leader_id' => $leader->id,
                'mode' => 'dual_run',
                'primary_count' => count($primaryTrades),
                'shadow_count' => count($shadowTrades),
                'only_primary' => $onlyPrimary,
                'only_shadow' => $onlyShadow,
            ], JSON_UNESCAPED_UNICODE));

            // 实际入库依然只以 data_api 为准，避免双源都写库导致重复。
            $this->persistTrades($leader, $primaryTrades, 'dual_run:data_api');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $trades
     *
     * 把拉回来的成交写入 pm_leader_trades。
     *
     * 去重键只看 trade_id：
     * - 相同 trade_id 再次拉到时只更新，不会重复插入
     * - 首次出现且成交时间在最近 30 分钟内，才认为是“新增可跟单成交”
     * - 对这类新增成交派发 PmCreateOrderIntentsJob 生成跟单意图
     */
    private function persistTrades(PmLeader $leader, array $trades, string $mode): void
    {
        $inserted = 0;
        // 只对最近 30 分钟内的新成交触发跟单，过旧成交即使首次入库也不再补派发。
        $time = time() - 1800;

        foreach ($trades as $normalized) {
            // token_id / trade_id 是后续跟单必需字段，缺失时直接跳过。
            if (empty($normalized['token_id']) || empty($normalized['trade_id'])) {
                continue;
            }

            // 调试输出：打印成交时间和归一化后的完整结构，便于排查来源数据异常。
            var_dump(date('Y-m-d H:i:s', $normalized['traded_at']), $normalized);
            $model = PmLeaderTrade::updateOrCreate(
                ['trade_id' => $normalized['trade_id']],
                array_merge($normalized, ['leader_id' => $leader->id])
            );

            // 只有“本次新插入”且成交时间足够新，才派发跟单意图任务。
            if ($model->wasRecentlyCreated && $model->traded_at > $time) {
                $inserted++;
                PmCreateOrderIntentsJob::dispatch($model->id);
            }
        }

        // 只要这轮确实新增了可跟单成交，就更新 leader 的最后活跃时间。
        if ($inserted > 0) {
            $leader->last_seen_trade_at = now();
            $leader->save();
        }

        $this->info("leader {$leader->id} 新增成交 {$inserted} 条 [{$mode}]");
    }

    /**
     * 根据配置值解析实际使用的数据源。
     */
    private function resolveSource(string $mode, DataApiLeaderTradeSource $dataSource, FastApiLeaderTradeSource $fastApiSource): LeaderTradeSourceInterface
    {
        return $mode === 'fastapi' ? $fastApiSource : $dataSource;
    }
}
