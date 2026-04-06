<?php

namespace App\Console\Commands;

use App\Services\Pm\PolymarketChainlinkStream;
use App\Services\Pm\TailSweepPriceCache;
use App\Services\Pm\TailSweepSymbolRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class PmTailSweepPriceDaemonCommand extends Command
{
    // 常驻行情 daemon；--once 仅用于本地调试，跑一轮后退出。
    protected $signature = 'pm:tail-sweep-price-daemon {--once : 仅执行一次循环，便于调试}';

    protected $description = '常驻订阅手动配置的 Chainlink 行情并写入共享缓存';

    public function handle(
        TailSweepSymbolRegistry $registry,
        TailSweepPriceCache $priceCache,
        PolymarketChainlinkStream $stream
    ): int {
        $once = (bool) $this->option('once');

        // 生产设计依赖 Redis 做跨进程共享缓存；若要求 Redis 但当前驱动不是 redis，则直接退出。
        if ((bool) config('pm.tail_sweep_price_require_redis', true) && $priceCache->driverName() !== 'redis') {
            $this->error('扫尾盘行情 daemon 需要 Redis cache store，请先配置 CACHE_DRIVER=redis 或 pm.tail_sweep_price_cache_store');

            return self::FAILURE;
        }

        // 用缓存锁保证同一环境下只运行一个 daemon，避免重复建连和重复写缓存。
        $lock = Cache::store($priceCache->configuredStore() ?? null)->lock(
            $priceCache->daemonLockKey(),
            max(10, (int) config('pm.tail_sweep_price_daemon_lock_seconds', 10))
        );

        try {
            // $lock->block(1);
        } catch (LockTimeoutException) {
            $this->warn('已有扫尾盘行情 daemon 正在运行，当前进程退出');

            return self::SUCCESS;
        }

        try {
            do {
                // 每次 runLoop 负责一次“建连 -> 订阅 -> 持续收消息”。
                // 若中途异常返回，外层循环会按配置 sleep 后重连。
                $result = $this->runLoop($registry, $priceCache, $stream);
                if ($once) {
                    return $result;
                }

                // 避免异常重连时无间隔疯狂重试。
                sleep(max(1, (int) config('pm.tail_sweep_price_daemon_reconnect_sleep_seconds', 3)));
            } while (true);
        } finally {
            optional($lock)->release();
        }
    }

    private function runLoop(
        TailSweepSymbolRegistry $registry,
        TailSweepPriceCache $priceCache,
        PolymarketChainlinkStream $stream
    ): int {
        // 读取当前期望订阅的 symbol 列表，并写入缓存，便于排障观察。
        $desiredSymbols = $registry->desiredSymbols();
        $priceCache->putDesiredSymbols($desiredSymbols);

        // 没有任何 symbol 需要订阅时，不建连，写心跳后进入空转。
        if ($desiredSymbols === []) {
            $priceCache->putSubscribedSymbols([]);
            $priceCache->putHeartbeat([
                'connected' => false,
                'symbols' => [],
                'updated_at' => time(),
                'message' => 'no_active_symbols',
            ]);
            $this->info('当前没有手动配置的订阅 symbol，daemon 进入空转');

            sleep(max(1, (int) config('pm.tail_sweep_price_daemon_idle_sleep_seconds', 5)));

            return self::SUCCESS;
        }

        $socket = null;
        // 当前连接上已经成功发起订阅的 symbol 集合。
        $subscribedSymbols = [];
        // 定期刷新配置中的 symbol，支持运行中手动新增订阅项。
        $refreshEvery = max(1, (int) config('pm.tail_sweep_price_symbol_refresh_seconds', 10));
        $lastRefreshAt = 0;

        try {
            // 首次建连时直接按当前配置的 symbol 全量订阅。
            $socket = $stream->connect($desiredSymbols);
            $subscribedSymbols = $desiredSymbols;
            $priceCache->putSubscribedSymbols($subscribedSymbols);
            $priceCache->putHeartbeat([
                'connected' => true,
                'symbols' => $subscribedSymbols,
                'updated_at' => time(),
                'message' => 'connected',
            ]);
            $this->info('扫尾盘行情 daemon 已连接，订阅 symbol: '.implode(', ', $subscribedSymbols));

            while (true) {
                $now = time();
                if ($now - $lastRefreshAt >= $refreshEvery) {
                    $lastRefreshAt = $now;
                    // 周期性重新读取配置，支持运行中新增 symbol。
                    $desiredSymbols = $registry->desiredSymbols();
                    $desiredSymbols = $priceCache->putDesiredSymbols($desiredSymbols);

                    // 只对新增的 symbol 做增量订阅；首版不做 unsubscribe。
                    $missing = array_values(array_diff($desiredSymbols, $subscribedSymbols));
                    if ($missing !== []) {
                        $stream->subscribe($socket, $missing);
                        $subscribedSymbols = $priceCache->putSubscribedSymbols(array_merge($subscribedSymbols, $missing));
                        $this->info('扫尾盘行情 daemon 新增订阅 symbol: '.implode(', ', $missing));
                    }
                }

                // 持续读取 RTDS 推送的实时消息；非有效行情消息会返回 null 并继续循环。
                $snapshot = $stream->readMessage($socket);

                if (!is_array($snapshot)) {
                    continue;
                }
              
                // 为快照补充本地接收时间，再写入共享缓存。
                $snapshot['received_at'] = time();
                $stored = $priceCache->putSnapshot($snapshot);
                // 每次成功收包后顺手刷新心跳，便于外部观察 daemon 是否活着。
                $priceCache->putHeartbeat([
                    'connected' => true,
                    'symbols' => $subscribedSymbols,
                    'updated_at' => time(),
                    'last_symbol' => $stored['symbol'] ?? null,
                    'last_value' => $stored['value'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // 连接异常、读取超时、订阅失败等都统一记录到心跳里，交给外层循环重连。
            $priceCache->putHeartbeat([
                'connected' => false,
                'symbols' => $subscribedSymbols,
                'updated_at' => time(),
                'last_error' => $e->getMessage(),
            ]);

            $message = $e->getMessage();
            $isRecoverable = str_contains($message, '读取超时')
                || str_contains($message, '连接已关闭')
                || str_contains($message, '强迫关闭了一个现有的连接');

            if ($isRecoverable) {
                $this->warn('扫尾盘行情 daemon 连接中断，准备重连: '.$message);
            } else {
                $this->error('扫尾盘行情 daemon 异常: '.$message);
            }

            return self::FAILURE;
        } finally {
            // 无论正常退出还是异常退出，都确保 socket 被关闭。
            if ($socket !== null) {
                $stream->disconnect($socket);
            }
        }
    }
}
