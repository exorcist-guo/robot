<?php

namespace App\Console\Commands;

use App\Services\Pm\MarketInfoCache;
use App\Services\Pm\MarketInfoRegistry;
use App\Services\Pm\PolymarketMarketStream;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class PmMarketInfoDaemonCommand extends Command
{
    // 常驻 market 信息订阅任务；--once 仅用于调试，跑一轮后退出。
    protected $signature = 'pm:market-info-daemon {--once : 仅执行一次循环，便于调试}';

    protected $description = '常驻订阅配置中的 Polymarket market 信息并写入共享缓存';

    public function handle(
        MarketInfoRegistry $registry,
        MarketInfoCache $cache,
        PolymarketMarketStream $stream
    ): int {
        $once = (bool) $this->option('once');

        // 这个 daemon 设计上依赖 Redis 做跨进程共享缓存；若要求 Redis 但当前 cache driver 不是 redis，则直接退出。
        if ((bool) config('pm.market_info_require_redis', true) && $cache->driverName() !== 'redis') {
            $this->error('market info daemon 需要 Redis cache store，请先配置 CACHE_DRIVER=redis 或 pm.market_info_cache_store');

            return self::FAILURE;
        }

        $store = $cache->configuredStore();
        $lockStore = $store !== null ? Cache::store($store) : Cache::store();
        // 用缓存锁保证同一环境只运行一个 market daemon，避免重复建连和重复写缓存。
        $lock = $lockStore->lock(
            $cache->daemonLockKey(),
            max(10, (int) config('pm.market_info_daemon_lock_seconds', 600))
        );

        try {
            $lock->block(1);
        } catch (LockTimeoutException) {
            $this->warn('已有 market info daemon 正在运行，当前进程退出');

            return self::SUCCESS;
        }

        try {
            do {
                // 每次 runLoop 负责一次“建连 -> 订阅 -> 持续收消息”。
                // 若中途异常返回，外层循环会按配置 sleep 后重连。
                $result = $this->runLoop($registry, $cache, $stream);
                if ($once) {
                    return $result;
                }

                // 避免异常后无间隔重试，给远端和本地都留一点缓冲时间。
                sleep(max(1, (int) config('pm.market_info_daemon_reconnect_sleep_seconds', 3)));
            } while (true);
        } finally {
            optional($lock)->release();
        }
    }

    private function runLoop(
        MarketInfoRegistry $registry,
        MarketInfoCache $cache,
        PolymarketMarketStream $stream
    ): int {
        // 读取当前任务推导出的订阅 market 列表。
        $desiredMarkets = $registry->desiredMarkets();

        // 没有任何订阅项时，不建连，写心跳后进入空转。
        if ($desiredMarkets === []) {
            $cache->putSubscribedMarkets([]);
            $cache->putHeartbeat([
                'connected' => false,
                'markets' => [],
                'updated_at' => time(),
                'message' => 'no_active_markets',
            ]);
            $this->info('当前没有可订阅的 market，daemon 进入空转');

            sleep(max(1, (int) config('pm.market_info_daemon_idle_sleep_seconds', 5)));

            return self::SUCCESS;
        }

        $socket = null;
        // 当前连接上已成功发起订阅的 market id 列表。
        $subscribedMarketIds = [];
        // 仅用于调试：收到第一条原始 websocket 消息时打印出来，便于确认协议格式。
        $printedFirstRawMessage = false;
        // 周期性刷新任务推导出的订阅 market，支持运行中自动进入新轮次。
        $refreshEvery = max(1, (int) config('pm.market_info_refresh_seconds', 10));
        $lastRefreshAt = 0;

        try {
            // 首次建连时，按当前任务推导出的 market 全量订阅。
            $socket = $stream->connect($desiredMarkets);
            $subscribedMarketIds = $cache->putSubscribedMarkets(array_column($desiredMarkets, 'market_id'));

            $cache->putHeartbeat([
                'connected' => true,
                'markets' => $subscribedMarketIds,
                'updated_at' => time(),
                'message' => 'connected',
            ]);
            $this->info('market info daemon 已连接，订阅 market: '.implode(', ', $subscribedMarketIds));

            while (true) {
                $now = time();
                if ($now - $lastRefreshAt >= $refreshEvery) {
                    $lastRefreshAt = $now;
                    // 周期性重新从任务推导订阅 market，支持运行中自动切到新轮次。
                    $desiredMarkets = $registry->desiredMarkets();
                    $desiredMarketIds = array_column($desiredMarkets, 'market_id');
                    // 只对新增的 market 做增量订阅；首版不做 unsubscribe。
                    $missingIds = array_values(array_diff($desiredMarketIds, $subscribedMarketIds));
                    if ($missingIds !== []) {
                        $missingMarkets = array_values(array_filter(
                            $desiredMarkets,
                            static fn (array $market): bool => in_array((string) ($market['market_id'] ?? ''), $missingIds, true)
                        ));
                        $stream->subscribe($socket, $missingMarkets);
                        $subscribedMarketIds = $cache->putSubscribedMarkets(array_merge($subscribedMarketIds, $missingIds));
                        $this->info('market info daemon 新增订阅 market: '.implode(', ', $missingIds));
                    }
                }

                // 持续读取 market websocket 的原始消息。
                $rawMessage = $stream->readRawMessage($socket);
                if (!$printedFirstRawMessage && is_array($rawMessage)) {
                    // 第一条原始消息直接打印，便于确认订阅是否成功、返回结构长什么样。
                    $printedFirstRawMessage = true;
                    $this->line('首条 market 原始消息:');
                    $this->line(json_encode($rawMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                // 把原始消息转换成统一快照结构；非目标消息或无法识别的消息会返回 null。
                $snapshot = is_array($rawMessage) ? $stream->extractSnapshot($rawMessage) : null;
                if (!is_array($snapshot)) {
                    continue;
                }

                // 为快照补充本地接收时间，再写入共享缓存。
                $snapshot['received_at'] = time();
                $stored = $cache->putSnapshot($snapshot);
                // 每次成功收包后顺手刷新 heartbeat，方便外部观测 daemon 是否仍正常工作。
                $cache->putHeartbeat([
                    'connected' => true,
                    'markets' => $subscribedMarketIds,
                    'updated_at' => time(),
                    'last_market_id' => $stored['market_id'] ?? null,
                    'last_event_type' => $stored['event_type'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // 连接异常、读取超时、订阅失败等都统一记录到 heartbeat，交给外层循环重连。
            $cache->putHeartbeat([
                'connected' => false,
                'markets' => $subscribedMarketIds,
                'updated_at' => time(),
                'last_error' => $e->getMessage(),
            ]);
            $this->error('market info daemon 异常: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            // 无论正常退出还是异常退出，都确保 socket 被关闭。
            if ($socket !== null) {
                $stream->disconnect($socket);
            }
        }
    }
}
