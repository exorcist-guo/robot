<?php

namespace App\Console\Commands;

use App\Jobs\PmCreateOrderIntentsJob;
use App\Models\Pm\PmLeader;
use App\Models\Pm\PmLeaderTrade;
use App\Services\Pm\PolygonRpcService;
use Illuminate\Console\Command;

class PmSyncLeaderTradesFromChainCommand extends Command
{
    protected $signature = 'pm:sync-leader-trades-from-chain {--once : 仅执行一次} {--leader_id= : 仅同步指定 leader} {--from-block= : 指定起始区块} {--to-block= : 指定结束区块}';

    protected $description = '使用 Polygon RPC 扫描 leader 链上事件，旁路验证低延迟成交发现';

    public function handle(PolygonRpcService $rpc): int
    {
        $head = $rpc->getBlockNumber();
        $fromBlock = $this->option('from-block');
        $toBlock = $this->option('to-block');
        $leaderId = $this->option('leader_id');

        $leaders = PmLeader::query()
            ->where('status', 1)
            ->when($leaderId !== null, fn ($query) => $query->where('id', (int) $leaderId))
            ->get();

        $from = is_numeric($fromBlock) ? (int) $fromBlock : max(0, $head - 50);
        $to = is_numeric($toBlock) ? (int) $toBlock : $head;

        foreach ($leaders as $leader) {
            $logs = $rpc->getLogs([
                'fromBlock' => $rpc->toRpcHex($from),
                'toBlock' => $rpc->toRpcHex($to),
                'address' => [
                    strtolower((string) config('pm.exchange_contract')),
                    strtolower((string) config('pm.ctf_contract')),
                    strtolower((string) config('pm.collateral_token')),
                ],
                'topics' => [],
            ]);

            $matched = 0;
            foreach ($logs as $log) {
                $raw = json_encode($log, JSON_UNESCAPED_UNICODE);
                if (!is_string($raw) || !str_contains(strtolower($raw), strtolower((string) $leader->proxy_wallet))) {
                    continue;
                }

                $tradeId = 'chain:' . strtolower((string) ($log['transactionHash'] ?? '')) . ':' . strtolower((string) ($log['logIndex'] ?? '0x0'));
                $model = PmLeaderTrade::updateOrCreate(
                    ['trade_id' => $tradeId],
                    [
                        'leader_id' => $leader->id,
                        'market_id' => null,
                        'token_id' => null,
                        'side' => 'BUY',
                        'price' => '0',
                        'size_usdc' => 0,
                        'raw' => [
                            'source' => 'polygon_rpc_probe',
                            'leader_proxy_wallet' => $leader->proxy_wallet,
                            'log' => $log,
                        ],
                        'traded_at' => now(),
                    ]
                );

                if ($model->wasRecentlyCreated) {
                    $matched++;
                }
            }

            $this->info("leader {$leader->id} 在区块 {$from}-{$to} 命中链上日志 {$matched} 条");
        }

        if ((bool) $this->option('once')) {
            $this->info('已按 --once 执行单次链上扫描');
        }

        return self::SUCCESS;
    }
}
