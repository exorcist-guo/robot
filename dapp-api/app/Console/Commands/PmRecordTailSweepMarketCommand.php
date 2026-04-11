<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmTailSweepMarketSnapshot;
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
    protected $signature = 'pm:record-tail-sweep-market
        {--once : 仅执行一次采样，便于调试}
        {--symbol=btc/usd : 默认标的}
        {--task_id= : 指定扫尾盘任务 ID}
        {--target_usdc=20 : 固定下单金额，单位 USDC}';

    protected $description = '每秒记录 BTC 当前价格、上涨下单价、下跌下单价和当前 5 分钟开盘价';

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
        $task = $this->resolveTask();
        if (!$task) {
            $this->warn('未找到启用中的扫尾盘任务，跳过本轮记录');

            return;
        }

        $defaultSymbol = $priceCache->normalizeSymbol((string) $this->option('symbol'));
        $baseSlug = $marketData->normalizeBaseSlug((string) $task->market_slug);
        if ($baseSlug === '') {
            $this->warn("任务 {$task->id} 缺少 market_slug，跳过本轮记录");

            return;
        }

        $roundStart = $marketData->getRoundStartTime($now);
        $roundEnd = $marketData->getRoundEndTime($now);
        $currentRoundSlug = $marketData->buildCurrentRoundSlug($baseSlug, $now);
        $market = $marketData->resolveCurrentRoundMarket($gammaClient, $currentRoundSlug);

        $symbol = $priceCache->normalizeSymbol((string) ($market['symbol'] ?? $task->market_symbol ?: $defaultSymbol));
        $priceSnapshot = $priceCache->getSnapshot($symbol);
        if (!$priceCache->isFresh($priceSnapshot)) {
            $this->warn("标的 {$symbol} 缓存行情缺失或已过期，跳过本轮记录");

            return;
        }

        $currentPrice = trim((string) ($priceSnapshot['value'] ?? '0'));
        if (!preg_match('/^\d+(\.\d+)?$/', $currentPrice) || bccomp($currentPrice, '0', 8) <= 0) {
            $this->warn("标的 {$symbol} 当前价格无效，跳过本轮记录");

            return;
        }

        $roundOpenPrice = $marketData->getStartPrice($roundStart, $roundEnd, $symbol);
        $targetUsdc = $this->resolveTargetUsdc();
        $books = [];

        $upEntryPrice = null;
        $upDepthReached = null;
        $tokenYesId = (string) ($market['token_yes_id'] ?? $task->token_yes_id ?? '');
        if ($tokenYesId !== '' && $trading->isTokenTradable($tokenYesId)) {
            [$upEntryPrice, , $upDepthReached] = $marketData->resolveEntryPrice(
                $trading,
                $tokenYesId,
                PolymarketTradingService::SIDE_BUY,
                $targetUsdc,
                $books
            );
            if (!is_string($upEntryPrice) || !preg_match('/^\d+(\.\d+)?$/', $upEntryPrice) || bccomp($upEntryPrice, '0', 8) <= 0) {
                $upEntryPrice = null;
            }
        }

        $downEntryPrice = null;
        $downDepthReached = null;
        $tokenNoId = (string) ($market['token_no_id'] ?? $task->token_no_id ?? '');
        if ($tokenNoId !== '' && $trading->isTokenTradable($tokenNoId)) {
            [$downEntryPrice, , $downDepthReached] = $marketData->resolveEntryPrice(
                $trading,
                $tokenNoId,
                PolymarketTradingService::SIDE_BUY,
                $targetUsdc,
                $books
            );
            if (!is_string($downEntryPrice) || !preg_match('/^\d+(\.\d+)?$/', $downEntryPrice) || bccomp($downEntryPrice, '0', 8) <= 0) {
                $downEntryPrice = null;
            }
        }

        PmTailSweepMarketSnapshot::query()->updateOrCreate(
            [
                'symbol' => $symbol,
                'snapshot_at' => $snapshotAt,
            ],
            [
                'round_start_at' => Carbon::createFromTimestamp($roundStart),
                'round_end_at' => Carbon::createFromTimestamp($roundEnd),
                'market_slug' => (string) ($market['slug'] ?? $currentRoundSlug),
                'market_id' => (string) ($market['market_id'] ?? ''),
                'token_yes_id' => $tokenYesId !== '' ? $tokenYesId : null,
                'token_no_id' => $tokenNoId !== '' ? $tokenNoId : null,
                'current_price' => $currentPrice,
                'round_open_price' => $this->normalizeNullableDecimal($roundOpenPrice),
                'up_entry_price' => $upEntryPrice,
                'down_entry_price' => $downEntryPrice,
                'target_usdc' => (int) $targetUsdc,
                'price_source' => 'tail_sweep_price_cache',
                'open_price_source' => 'polymarket_crypto_price_open',
                'entry_price_source' => 'orderbook_market_price',
                'raw' => [
                    'task_id' => $task->id,
                    'price_snapshot_timestamp' => $priceSnapshot['timestamp'] ?? null,
                    'price_snapshot_received_at' => $priceSnapshot['received_at'] ?? null,
                    'market_question' => $market['question'] ?? null,
                    'resolution_source' => $market['resolution_source'] ?? null,
                    'price_to_beat' => $market['price_to_beat'] ?? null,
                    'up_depth_reached' => $upDepthReached,
                    'down_depth_reached' => $downDepthReached,
                ],
            ]
        );

        $this->info("行情已记录: {$symbol} price={$currentPrice} up={$upEntryPrice} down={$downEntryPrice} open={$roundOpenPrice}");
    }

    private function resolveTask(): ?PmCopyTask
    {
        $taskId = $this->option('task_id');

        return PmCopyTask::query()
            ->where('mode', PmCopyTask::MODE_TAIL_SWEEP)
            ->where('status', 1)
            ->when($taskId, fn ($query) => $query->where('id', (int) $taskId))
            ->whereNotNull('market_slug')
            ->orderBy('id')
            ->first([
                'id',
                'market_slug',
                'market_symbol',
                'token_yes_id',
                'token_no_id',
            ]);
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
