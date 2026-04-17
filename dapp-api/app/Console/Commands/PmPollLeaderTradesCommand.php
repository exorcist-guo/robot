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
    protected $signature = 'pm:poll-leader-trades {--once : 仅执行一次，不循环}';

    protected $description = '轮询 leader 的 Polymarket 成交并生成跟单意图';

    public function handle(DataApiLeaderTradeSource $dataSource, FastApiLeaderTradeSource $fastApiSource): int
    {
        $once = (bool) $this->option('once');
        $mode = (string) config('dapp_py.leader_trade_mode', 'data_api');

        do {
            if ($mode === 'dual_run') {
                $this->pollDualRun($dataSource, $fastApiSource);
            } else {
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

    private function poll(LeaderTradeSourceInterface $source, string $mode): void
    {
        $leaders = PmLeader::where('status', 1)->get();
        foreach ($leaders as $leader) {
            try {
                $trades = $source->fetchTradesByUser($leader->proxy_wallet, 10, 0);
            } catch (\Throwable $e) {
                $this->error("leader {$leader->id} 拉取 trades 失败[{$mode}]: {$e->getMessage()}");
                continue;
            }

            $this->persistTrades($leader, $trades, $mode);
        }
    }

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

            $primaryIds = collect($primaryTrades)->pluck('trade_id')->filter()->values()->all();
            $shadowIds = collect($shadowTrades)->pluck('trade_id')->filter()->values()->all();
            $onlyPrimary = array_values(array_diff($primaryIds, $shadowIds));
            $onlyShadow = array_values(array_diff($shadowIds, $primaryIds));

            $this->line(json_encode([
                'leader_id' => $leader->id,
                'mode' => 'dual_run',
                'primary_count' => count($primaryTrades),
                'shadow_count' => count($shadowTrades),
                'only_primary' => $onlyPrimary,
                'only_shadow' => $onlyShadow,
            ], JSON_UNESCAPED_UNICODE));

            $this->persistTrades($leader, $primaryTrades, 'dual_run:data_api');
        }
    }

    /**
     * @param array<int,array<string,mixed>> $trades
     */
    private function persistTrades(PmLeader $leader, array $trades, string $mode): void
    {
        $inserted = 0;
        $time = time() - 1800;

        foreach ($trades as $normalized) {

            if (empty($normalized['token_id']) || empty($normalized['trade_id'])) {
                continue;
            }

            // var_dump(date('Y-m-d H:i:s',$normalized['traded_at']));
            $model = PmLeaderTrade::updateOrCreate(
                ['trade_id' => $normalized['trade_id']],
                array_merge($normalized, ['leader_id' => $leader->id])
            );

            if ($model->wasRecentlyCreated && $model->traded_at > $time) {
                $inserted++;
                PmCreateOrderIntentsJob::dispatch($model->id);
            }
        }

        if ($inserted > 0) {
            $leader->last_seen_trade_at = now();
            $leader->save();
        }

        $this->info("leader {$leader->id} 新增成交 {$inserted} 条 [{$mode}]");
    }

    private function resolveSource(string $mode, DataApiLeaderTradeSource $dataSource, FastApiLeaderTradeSource $fastApiSource): LeaderTradeSourceInterface
    {
        return $mode === 'fastapi' ? $fastApiSource : $dataSource;
    }
}
