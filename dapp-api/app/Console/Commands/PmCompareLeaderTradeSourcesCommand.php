<?php

namespace App\Console\Commands;

use App\Models\Pm\PmLeader;
use App\Services\DappPy\DappPyClient;
use App\Services\Pm\Sources\DataApiLeaderTradeSource;
use Illuminate\Console\Command;

class PmCompareLeaderTradeSourcesCommand extends Command
{
    protected $signature = 'pm:compare-leader-trade-sources {--leader_id= : 仅对比指定 leader} {--limit=10 : 每个 leader 拉取条数}';

    protected $description = '对比 Laravel Data API 与 dapp-py 返回的 leader trades，不写入主表';

    public function handle(DataApiLeaderTradeSource $dataSource, DappPyClient $client): int
    {
        $leaderId = $this->option('leader_id');
        $limit = max(1, (int) $this->option('limit'));

        $leaders = PmLeader::query()
            ->where('status', 1)
            ->when($leaderId !== null, fn ($query) => $query->where('id', (int) $leaderId))
            ->get();

        foreach ($leaders as $leader) {
            try {
                $oldItems = $dataSource->fetchTradesByUser($leader->proxy_wallet, $limit, 0);
                $compare = $client->compareLeaderTrades($leader->proxy_wallet, $limit, 0);
            } catch (\Throwable $e) {
                $this->error("leader {$leader->id} 对比失败: {$e->getMessage()}");
                continue;
            }

            $this->info("leader {$leader->id} 对比完成");
            $this->line(json_encode([
                'leader_id' => $leader->id,
                'proxy_wallet' => $leader->proxy_wallet,
                'laravel_count' => count($oldItems),
                'compare' => $compare,
            ], JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
