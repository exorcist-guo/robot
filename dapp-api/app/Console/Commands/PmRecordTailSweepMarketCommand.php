<?php

namespace App\Console\Commands;

use App\Models\Pm\PmTailSweepRoundOpenPrice;
use App\Services\Pm\TailSweepMarketDataService;
use App\Services\Pm\TailSweepPriceCache;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PmRecordTailSweepMarketCommand extends Command
{
    protected $signature = 'pm:record-tail-sweep-market
        {--once : 仅执行一次采样，便于调试}
        {--symbol=btc/usd : 默认标的}';

    protected $description = '每秒记录 BTC 当前 5 分钟开盘价';
    public function handle(
        TailSweepPriceCache $priceCache,
        TailSweepMarketDataService $marketData
    ): int {
        $once = (bool) $this->option('once');
        $cacheStore = $this->cacheStore();
        $lockStoreName = config('pm.tail_sweep_scan_cache_store');
        $lockStore = $lockStoreName !== null && $lockStoreName !== '' ? Cache::store($lockStoreName) : Cache::store();
        $lockProvider = $lockStore->getStore();

        if ((bool) config('pm.tail_sweep_scan_require_redis', true) && $cacheStore->getStore() instanceof \Illuminate\Cache\RedisStore === false) {
            $this->error('行情记录 daemon 需要 Redis cache store，请先配置 CACHE_DRIVER=redis 或 pm.tail_sweep_scan_cache_store');

            return self::FAILURE;
        }

        if (!$lockProvider instanceof LockProvider) {
            $this->error('当前行情记录 cache store 不支持 lock，请切换到 Redis cache store');

            return self::FAILURE;
        }

        $lock = $lockProvider->lock(
            $this->daemonLockKey(),
            max(10, (int) config('pm.tail_sweep_scan_lock_seconds', 10))
        );

        try {
            $lock->block(1);
        } catch (LockTimeoutException) {
            $this->warn('已有行情记录 daemon 正在运行，当前进程退出');

            return self::SUCCESS;
        }

        try {
            do {
                try {
                    $this->reconnectRedis();
                    $this->recordSnapshot($priceCache, $marketData);

                    if ($once) {
                        $this->info('行情记录完成');

                        return self::SUCCESS;
                    }
                } catch (\Throwable $e) {
                    $this->error('行情记录异常: '.$e->getMessage());
                    if ($once) {
                        return self::FAILURE;
                    }

                    try {
                        $this->reconnectRedis();
                    } catch (\Throwable $reconnectError) {
                        $this->warn('Redis 重连失败: '.$reconnectError->getMessage());
                    }
                }

                sleep(1);
            } while (true);
        } finally {
            optional($lock)->release();
        }
    }

    private function recordSnapshot(
        TailSweepPriceCache $priceCache,
        TailSweepMarketDataService $marketData
    ): void {
        $now = now();
        $defaultSymbol = $priceCache->normalizeSymbol((string) $this->option('symbol'));
        $roundStart = Carbon::createFromTimestamp($marketData->getRoundStartTime($now));
        $roundEnd = Carbon::createFromTimestamp($marketData->getRoundEndTime($now));
        $roundOpenPrice = $this->normalizeNullableDecimal($marketData->getStartPrice($roundStart->timestamp, $roundEnd->timestamp, $defaultSymbol));

        if ($roundOpenPrice === null) {
            $this->warn("标的 {$defaultSymbol} 当前轮开盘价缺失，跳过本轮");

            return;
        }

        PmTailSweepRoundOpenPrice::query()->updateOrCreate(
            [
                'symbol' => $defaultSymbol,
                'round_start_at' => $roundStart,
            ],
            [
                'round_end_at' => $roundEnd,
                'round_open_price' => $roundOpenPrice,
            ]
        );
    }


    private function normalizeNullableDecimal(?string $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d+(\.\d+)?$/', $value) || bccomp($value, '0', 8) <= 0) {
            return null;
        }

        return $value;
    }

    private function daemonLockKey(): string
    {
        return 'pm:tail_sweep:market_snapshot:run';
    }

    private function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('pm.tail_sweep_scan_cache_store');

        return $store !== null && $store !== '' ? Cache::store($store) : Cache::store();
    }

    private function reconnectRedis(): void
    {
        try {
            Cache::getStore()->getRedis()->ping();
        } catch (\Throwable) {
            try {
                $app = app();
                $app->forgetInstance('cache');
                $app->forgetInstance('cache.store');
                Cache::purge();
            } catch (\Throwable) {
            }
        }

        try {
            \Illuminate\Support\Facades\Redis::connection()->ping();
        } catch (\Throwable) {
            try {
                \Illuminate\Support\Facades\Redis::purge();
            } catch (\Throwable) {
            }
        }
    }
}
