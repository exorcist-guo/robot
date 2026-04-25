<?php

namespace App\Console\Commands;

use App\Jobs\PmCreateOrderIntentsJob;
use App\Models\Pm\PmLeader;
use App\Models\Pm\PmLeaderTrade;
use App\Services\Pm\Sources\DataApiLeaderTradeSource;
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
     * 固定使用 Polymarket Data API 作为成交来源。
     * 默认每 5 秒轮询一次；传 --once 时只执行一轮。
     */
    public function handle(DataApiLeaderTradeSource $dataSource): int
    {
        $once = (bool) $this->option('once');

        do {
            $this->poll($dataSource);
            if ($once) {
                $this->info('已按 --once 执行单次轮询');
                break;
            }
            sleep(5);
        } while (true);

        return self::SUCCESS;
    }

    /**
     * 轮询所有启用中的 leader。
     */
    private function poll(DataApiLeaderTradeSource $source): void
    {
        // 只处理状态为启用(status=1)的 leader。
        $leaders = PmLeader::where('status', 1)->get();
        foreach ($leaders as $leader) {
            try {
                // 每次只取最近 10 条成交，offset=0 表示从最新开始。
                $trades = $source->fetchTradesByUser($leader->proxy_wallet, 10, 0);
            } catch (\Throwable $e) {
                $this->error("leader {$leader->id} 拉取 trades 失败[data_api]: {$e->getMessage()}");
                continue;
            }

            $this->persistTrades($leader, $trades);
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
    private function persistTrades(PmLeader $leader, array $trades): void
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
            // var_dump(date('Y-m-d H:i:s', $normalized['traded_at']), $normalized);
            $model = PmLeaderTrade::updateOrCreate(
                ['trade_id' => $normalized['trade_id']],
                array_merge($normalized, [
                    'leader_id' => $leader->id,
                    'leader_role' => $normalized['leader_role'] ?? ($normalized['raw']['leader_role'] ?? null),
                ])
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

        $this->info("leader {$leader->id} 新增成交 {$inserted} 条 [data_api]");
    }
}
