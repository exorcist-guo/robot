<?php

namespace App\Console\Commands;

use App\Models\Pm\PmTailSweepMarketSnapshot;
use App\Models\Pm\PmTailSweepRoundOpenPrice;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketTradingService;
use App\Services\Pm\TailSweepMarketDataService;
use App\Services\Pm\TailSweepPriceCache;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PmRecordTailSweepMarketCommand extends Command
{
    private const BASE_MARKET_SLUGS = [
        'btc-updown-5m',
        'btc-updown-15m',
    ];

    protected $signature = 'pm:record-tail-sweep-market
        {--once : 仅执行一次采样，便于调试}
        {--symbol=btc/usd : 默认标的}
        {--target_usdc=20 : 固定下单金额，单位 USDC}';

    protected $description = '每秒记录 BTC 当前价格、5m/15m 上涨下单价、下跌下单价和当前 5 分钟开盘价';
    public function handle(
        GammaClient $gammaClient,
        TailSweepPriceCache $priceCache,
        PolymarketTradingService $trading,
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
                    $this->recordSnapshot($gammaClient, $priceCache, $trading, $marketData);

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
        GammaClient $gammaClient,
        TailSweepPriceCache $priceCache,
        PolymarketTradingService $trading,
        TailSweepMarketDataService $marketData
    ): void {
        $now = now();
        $snapshotAt = Carbon::createFromTimestamp($now->timestamp);
        $defaultSymbol = $priceCache->normalizeSymbol((string) $this->option('symbol'));
        $roundStart = Carbon::createFromTimestamp($marketData->getRoundStartTime($now));
        $roundEnd = Carbon::createFromTimestamp($marketData->getRoundEndTime($now));
        $targetUsdc = $this->resolveTargetUsdc();
        $priceSnapshot = $priceCache->getSnapshot($defaultSymbol);

        if (!$priceCache->isFresh($priceSnapshot)) {
            $this->warn("标的 {$defaultSymbol} 缓存行情缺失或已过期，跳过本轮");

            return;
        }

        $currentPrice = trim((string) ($priceSnapshot['value'] ?? '0'));
        if (!preg_match('/^\d+(\.\d+)?$/', $currentPrice) || bccomp($currentPrice, '0', 8) <= 0) {
            $this->warn("标的 {$defaultSymbol} 当前价格无效，跳过本轮");

            return;
        }

        $roundOpenPrice = $this->normalizeNullableDecimal($marketData->getStartPrice($roundStart->timestamp, $roundEnd->timestamp, $defaultSymbol));
        $snapshotPayload = [
            'current_price' => $currentPrice,
            'up_entry_price5m' => null,
            'down_entry_price5m' => null,
            'up_entry_price15m' => null,
            'down_entry_price15m' => null,
            'target_usdc' => (int) $targetUsdc,
        ];

        foreach (self::BASE_MARKET_SLUGS as $baseSlug) {
            $currentRoundSlug = $marketData->buildCurrentRoundSlug($baseSlug, $now);

            try {
                $market = $marketData->resolveCurrentRoundMarket($gammaClient, $currentRoundSlug);
            } catch (\Throwable $e) {
                $this->warn("{$baseSlug} 当前轮 market 解析失败，跳过: {$e->getMessage()}");
                continue;
            }

            $books = [];
            $upEntryPrice = null;
            $downEntryPrice = null;
            $tokenYesId = (string) ($market['token_yes_id'] ?? '');
            $tokenNoId = (string) ($market['token_no_id'] ?? '');

            if ($tokenYesId !== '' && $trading->isTokenTradable($tokenYesId)) {
                [$upEntryPrice] = $marketData->resolveEntryPrice(
                    $trading,
                    $tokenYesId,
                    PolymarketTradingService::SIDE_BUY,
                    $targetUsdc,
                    $books
                );
                $upEntryPrice = $this->normalizeNullableDecimal($upEntryPrice);
            }

            if ($tokenNoId !== '' && $trading->isTokenTradable($tokenNoId)) {
                [$downEntryPrice] = $marketData->resolveEntryPrice(
                    $trading,
                    $tokenNoId,
                    PolymarketTradingService::SIDE_BUY,
                    $targetUsdc,
                    $books
                );
                $downEntryPrice = $this->normalizeNullableDecimal($downEntryPrice);
            }

            if ($baseSlug === 'btc-updown-5m') {
                $snapshotPayload['up_entry_price5m'] = $upEntryPrice;
                $snapshotPayload['down_entry_price5m'] = $downEntryPrice;
            }

            if ($baseSlug === 'btc-updown-15m') {
                $snapshotPayload['up_entry_price15m'] = $upEntryPrice;
                $snapshotPayload['down_entry_price15m'] = $downEntryPrice;
            }

            // $this->info("行情已聚合: {$baseSlug} {$defaultSymbol} price={$currentPrice} up={$upEntryPrice} down={$downEntryPrice} open={$roundOpenPrice}");
        }

        PmTailSweepMarketSnapshot::query()->updateOrCreate(
            [
                'symbol' => $defaultSymbol,
                'snapshot_at' => $snapshotAt,
            ],
            $snapshotPayload
        );

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

    private function resolveTargetUsdc(): string
    {
        $target = trim((string) $this->option('target_usdc'));
        if (!preg_match('/^\d+(\.\d+)?$/', $target) || bccomp($target, '0', 6) <= 0) {
            $target = '20';
        }

        return bcmul($target, '1000000', 0);
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
