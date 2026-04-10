<?php

namespace App\Console\Commands;

use App\Jobs\PmExecuteOrderIntentJob;
use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketTradingService;
use App\Services\Pm\TailSweepPriceCache;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PmScanTailSweepManyCommand extends Command
{
    // 常驻扫尾盘多单扫描；--once 仅用于调试，跑一轮后退出。
    protected $signature = 'pm:scan-tail-sweep-many {--once : 仅执行一次扫描，便于调试}';

    protected $description = '常驻扫描扫尾盘多单任务并在满足条件时生成多个下单意图';

    public function handle(
        GammaClient $gammaClient,
        TailSweepPriceCache $priceCache,
        PolymarketTradingService $trading
    ): int
    {
        $once = (bool) $this->option('once');
        $cacheStore = $this->cacheStore();
        $lockStoreName = config('pm.tail_sweep_scan_cache_store');
        $lockStore = $lockStoreName !== null && $lockStoreName !== '' ? Cache::store($lockStoreName) : Cache::store();
        $lockProvider = $lockStore->getStore();

        if ((bool) config('pm.tail_sweep_scan_require_redis', true) && $cacheStore->getStore() instanceof \Illuminate\Cache\RedisStore === false) {
            $this->error('扫尾盘扫描 daemon 需要 Redis cache store，请先配置 CACHE_DRIVER=redis 或 pm.tail_sweep_scan_cache_store');

            return self::FAILURE;
        }

        if (!$lockProvider instanceof LockProvider) {
            $this->error('当前扫尾盘扫描 cache store 不支持 lock，请切换到 Redis cache store');

            return self::FAILURE;
        }

        $lock = $lockProvider->lock(
            $this->daemonLockKey(),
            max(10, (int) config('pm.tail_sweep_scan_lock_seconds', 10))
        );

        try {
            $lock->block(1);
        } catch (LockTimeoutException) {
            $this->warn('已有扫尾盘扫描 daemon 正在运行，当前进程退出');

            return self::SUCCESS;
        }

        try {
            do {
                try {
                    // 每轮扫描前重连 Redis，避免连接超时
                    $this->reconnectRedis();

                    $this->scan($gammaClient, $priceCache, $trading);
                    if ($once) {
                        $this->info('扫尾盘扫描完成');

                        return self::SUCCESS;
                    }
                } catch (\Throwable $e) {
                    $this->error('扫尾盘扫描异常: '.$e->getMessage());
                    if ($once) {
                        return self::FAILURE;
                    }

                    // 异常后也尝试重连，避免连接问题持续
                    try {
                        $this->reconnectRedis();
                    } catch (\Throwable $reconnectError) {
                        $this->warn('Redis 重连失败: '.$reconnectError->getMessage());
                    }
                }

                sleep(max(1, (int) config('pm.tail_sweep_scan_loop_sleep_seconds', 5)));
            } while (true);
        } finally {
            optional($lock)->release();
        }
    }

    private function scan(
        GammaClient $gammaClient,
        TailSweepPriceCache $priceCache,
        PolymarketTradingService $trading
    ): void
    {
        $now = now();

        $tasks = $this->withRedisRetry(fn() => PmCopyTask::query()
            ->where('mode', PmCopyTask::MODE_TAIL_SWEEP_MANY)
            ->where('status', 1)
            ->get([
                'id',
                'member_id',
                'status',
                'market_slug',
                'market_id',
                'market_question',
                'market_symbol',
                'resolution_source',
                'price_to_beat',
                'market_end_at',
                'token_yes_id',
                'token_no_id',
                'tail_order_usdc',
                'tail_trigger_amount',
                'tail_time_limit_seconds',
                'tail_loss_stop_count',
                'tail_loss_count',
                'tail_round_started_value',
                'tail_last_triggered_round_key',
                'tail_loss_stopped_at',
                'max_slippage_bps',
                'allow_partial_fill',
                'daily_max_usdc',
                'tail_price_time_config',
            ]));

        $this->preCheckWalletReadiness($tasks, $trading);

        $snapshots = [];
        $books = [];

        foreach ($tasks as $task) {
            $baseSlug = $this->normalizeBaseSlug((string) $task->market_slug);
            if ($baseSlug !== '') {
                $currentRoundSlug = $this->buildCurrentRoundSlug($baseSlug, $now);
                $starTime = $this->starTime($now);
                $endTime = $starTime + 300;
                $market_end_at = date('Y-m-d H:i:s', $endTime);
                $needsRefresh = !$task->market_end_at || (string) $task->market_end_at->timestamp !== (string) $endTime;

                if ($needsRefresh) {
                    try {
                        $market = $this->resolveCurrentRoundMarket($gammaClient, $currentRoundSlug);
                    } catch (\Throwable $e) {
                        $this->warn("任务 {$task->id} 刷新当前轮 market 失败: {$e->getMessage()}");
                        continue;
                    }

                    if (!is_array($market) || $market === []) {
                        continue;
                    }

                    $task->market_id = (string) ($market['market_id'] ?? '');
                    $task->market_question = (string) ($market['question'] ?? '');
                    $task->market_symbol = (string) ($market['symbol'] ?? $task->market_symbol ?: 'btc/usd');
                    $task->resolution_source = (string) ($market['resolution_source'] ?? '');
                    $task->price_to_beat = (string) ($market['price_to_beat'] ?? '0');
                    $task->token_yes_id = (string) ($market['token_yes_id'] ?? '');
                    $task->token_no_id = (string) ($market['token_no_id'] ?? '');
                    $task->market_end_at = $market_end_at;
                    $task->tail_round_started_value = null;
                    $task->tail_last_triggered_round_key = null;
                    $task->save();
                    $task->refresh();
                }
            }

            $marketEndAt = $task->market_end_at;
            if (!$marketEndAt) {
                continue;
            }

            $remainingSeconds = $now->diffInSeconds($marketEndAt, false);
            if ($remainingSeconds <= 0) {
                if ($task->tail_round_started_value !== null || $task->tail_last_triggered_round_key !== null) {
                    $task->tail_round_started_value = null;
                    $task->tail_last_triggered_round_key = null;
                    $task->save();
                }
                continue;
            }

            if ($task->tail_loss_stop_count > 0 && $task->tail_loss_count >= $task->tail_loss_stop_count) {
                if ($task->status !== 0 || $task->tail_loss_stopped_at === null) {
                    $task->status = 0;
                    $task->tail_loss_stopped_at = $now;
                    $task->save();
                }
                continue;
            }

            $symbol = $priceCache->normalizeSymbol((string) ($task->market_symbol ?: 'btc/usd'));
            if (!array_key_exists($symbol, $snapshots)) {
                $snapshot = $priceCache->getSnapshot($symbol);
                if (!$priceCache->isFresh($snapshot)) {
                    $snapshots[$symbol] = null;
                    $this->warn("标的 {$symbol} 缓存行情缺失或已过期，跳过本轮扫描");
                } else {
                    $snapshots[$symbol] = $snapshot;
                }
            }

            $snapshot = $snapshots[$symbol];
            if (!is_array($snapshot)) {
                continue;
            }

            $currentPrice = (string) ($snapshot['value'] ?? '0');
            if (!preg_match('/^\d+(\.\d+)?$/', $currentPrice)) {
                continue;
            }

            $roundKey = (string) $marketEndAt->timestamp;
            $startPrice = $this->getStartPrice($starTime, $endTime, $symbol);
            $change = bcsub($currentPrice, $startPrice, 8);
            $absChange = bcmul($change, $change[0] === '-' ? '-1' : '1', 8);

            // 多单模式规则定义（硬编码）
            // 规则0: 价差 > 15, 时间 < 210s, 下单 20 USDC
            // 规则1: 价差 > 25, 时间 < 90s, 下单 30 USDC
            // 规则2: 价差 > 45, 时间 < 15s, 下单 45 USDC
            // 规则3: 价差 < 15, 时间 12-15s, 反方向下单 10 USDC
            $rules = [
                ['price_threshold' => '50', 'time_max' => 210, 'usdc' => 2000000, 'reverse' => false],
                ['price_threshold' => '40', 'time_max' => 90, 'usdc' => 3000000, 'reverse' => false],
                ['price_threshold' => '26', 'time_max' => 15, 'usdc' => 4500000, 'reverse' => false],
                ['price_threshold' => '15', 'time_min' => 12, 'time_max' => 15, 'usdc' => 1000000, 'reverse' => true],
            ];

            // 查询本轮已下单记录
            $existingIntents = PmOrderIntent::query()
                ->where('copy_task_id', $task->id)
                ->where('risk_snapshot->round_key', $roundKey)
                ->orderBy('id')
                ->get(['id', 'risk_snapshot']);

            $placedRules = [];
            foreach ($existingIntents as $intent) {
                $ruleIndex = $intent->risk_snapshot['rule_index'] ?? null;
                if ($ruleIndex !== null) {
                    $placedRules[] = $ruleIndex;
                }
            }

            // 确定第一单的价差方向（正或负）
            $firstDirection = null;
            if (!empty($placedRules)) {
                $firstIntent = $existingIntents->first();
                $firstChange = $firstIntent->risk_snapshot['change'] ?? null;
                if ($firstChange !== null) {
                    $firstDirection = bccomp($firstChange, '0', 8) >= 0 ? 'positive' : 'negative';
                }
            }

            // 当前价差方向
            $currentDirection = bccomp($change, '0', 8) >= 0 ? 'positive' : 'negative';

            // 按顺序检查规则
            foreach ($rules as $ruleIndex => $rule) {
                // 已下过此规则，跳过
                if (in_array($ruleIndex, $placedRules, true)) {
                    continue;
                }

                // 必须按顺序下单：如果前面的规则还没下，不能跳过
                for ($i = 0; $i < $ruleIndex; $i++) {
                    if (!in_array($i, $placedRules, true)) {
                        continue 2;
                    }
                }

                // 方向一致性检查：第一单确定方向后，后续非反向单必须保持一致
                if ($firstDirection !== null && !$rule['reverse']) {
                    if ($currentDirection !== $firstDirection) {
                        continue;
                    }
                }

                // 检查价差条件
                $priceMatch = false;
                if ($rule['reverse']) {
                    // 规则4：价差 < 15
                    $priceMatch = bccomp($absChange, $rule['price_threshold'], 8) < 0;
                } else {
                    // 规则1-3：价差 >= 阈值
                    $priceMatch = bccomp($absChange, $rule['price_threshold'], 8) >= 0;
                }

                if (!$priceMatch) {
                    continue;
                }

                // 检查时间条件
                $timeMatch = false;
                if (isset($rule['time_min']) && isset($rule['time_max'])) {
                    // 规则4：时间在 12-15 秒之间
                    $timeMatch = $remainingSeconds >= $rule['time_min'] && $remainingSeconds <= $rule['time_max'];
                } elseif (isset($rule['time_max'])) {
                    // 规则1-3：时间 <= 上限
                    $timeMatch = $remainingSeconds <= $rule['time_max'];
                }

                if (!$timeMatch) {
                    continue;
                }

                // 确定下单方向
                $side = PolymarketTradingService::SIDE_BUY;
                $tokenId = null;
                $triggerSide = null;

                if ($rule['reverse']) {
                    // 反方向下单
                    if (bccomp($change, '0', 8) > 0) {
                        $tokenId = (string) $task->token_no_id;
                        $triggerSide = 'down';
                    } elseif (bccomp($change, '0', 8) < 0) {
                        $tokenId = (string) $task->token_yes_id;
                        $triggerSide = 'up';
                    } else {
                        continue;
                    }
                } else {
                    // 正方向下单
                    if (bccomp($change, '0', 8) > 0) {
                        $tokenId = (string) $task->token_yes_id;
                        $triggerSide = 'up';
                    } elseif (bccomp($change, '0', 8) < 0) {
                        $tokenId = (string) $task->token_no_id;
                        $triggerSide = 'down';
                    } else {
                        continue;
                    }
                }

                if (!$tokenId || !$triggerSide) {
                    continue;
                }

                if (!$trading->isTokenTradable($tokenId)) {
                    $this->warn("任务 {$task->id} token 不可交易: {$tokenId}");
                    continue;
                }

                [$entryPrice, $entryPriceSource] = $this->resolveEntryPrice($trading, $tokenId, $side, (string) $rule['usdc'], $books);
                if (!preg_match('/^\d+(\.\d+)?$/', $entryPrice) || bccomp($entryPrice, '0', 8) <= 0) {
                    continue;
                }

                // 创建下单意图
                $intent = PmOrderIntent::create([
                    'copy_task_id' => $task->id,
                    'leader_trade_id' => null,
                    'member_id' => $task->member_id,
                    'token_id' => $tokenId,
                    'side' => $side,
                    'leader_price' => $entryPrice,
                    'target_usdc' => (int) $rule['usdc'],
                    'clamped_usdc' => (int) $rule['usdc'],
                    'status' => PmOrderIntent::STATUS_PENDING,
                    'skip_reason' => null,
                    'risk_snapshot' => [
                        'mode' => PmCopyTask::MODE_TAIL_SWEEP_MANY,
                        'max_slippage_bps' => $task->max_slippage_bps,
                        'allow_partial_fill' => (bool) $task->allow_partial_fill,
                        'daily_max_usdc' => $task->daily_max_usdc,
                        'round_key' => $roundKey,
                        'start_time' => $starTime,
                        'end_time' => $endTime,
                        'market_slug' => $task->market_slug,
                        'market_id' => $task->market_id,
                        'market_question' => $task->market_question,
                        'resolution_source' => $task->resolution_source,
                        'trigger_side' => $triggerSide,
                        'trigger_amount' => $rule['price_threshold'],
                        'current_price' => $currentPrice,
                        'round_start_price' => $startPrice,
                        'price_to_beat' => (string) ($task->price_to_beat ?: '0'),
                        'change' => $change,
                        'remaining_seconds' => $remainingSeconds,
                        'token_yes_id' => $task->token_yes_id,
                        'token_no_id' => $task->token_no_id,
                        'entry_price' => $entryPrice,
                        'entry_price_source' => $entryPriceSource,
                        'rule_index' => $ruleIndex,
                        'rule_reverse' => $rule['reverse'],
                    ],
                    'price_time_limit' => "rule{$ruleIndex}-{$rule['price_threshold']}-{$rule['time_max']}",
                ]);

                PmExecuteOrderIntentJob::dispatch($intent->id);
                $intent->refresh();
                $order = $intent->order()->latest('id')->first();
                $this->info("任务 {$task->id} 规则{$ruleIndex} 触发 - 价差={$absChange}, 剩余={$remainingSeconds}s, 金额=".($rule['usdc']/1000000)." USDC, Intent: {$intent->id}, Order: ".($order?->id ?? 'N/A'));

                // 记录已下单规则
                $placedRules[] = $ruleIndex;

                // 如果是第一单，记录方向
                if ($firstDirection === null) {
                    $firstDirection = $currentDirection;
                }
            }
        }
    }

    private function buildCurrentRoundSlug(string $baseSlug, Carbon $now): string
    {
        $minutes = (int) $now->format('i');
        $targetMinutes = (int) (floor($minutes / 5) * 5);
        $timestamp = strtotime($now->format('Y-m-d H:').sprintf('%02d', $targetMinutes).':00');

        return $baseSlug.'-'.$timestamp;
    }

    private function starTime(Carbon $now): int
    {
        $minutes = (int) $now->format('i');
        $targetMinutes = (int) (floor($minutes / 5) * 5);
        $timestamp = strtotime($now->format('Y-m-d H:').sprintf('%02d', $targetMinutes).':00');

        return $timestamp;
    }

    private function normalizeBaseSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        return (string) (preg_replace('/-\d{10}$/', '', $slug) ?: $slug);
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveCurrentRoundMarket(GammaClient $gammaClient, string $currentRoundSlug): array
    {
        $store = $this->cacheStore();
        $cacheKey = $this->marketCacheKey($currentRoundSlug);
        $cached = $store->get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $market = $gammaClient->resolveTailSweepMarket($currentRoundSlug);
        if (!is_array($market) || $market === []) {
            throw new \RuntimeException("当前轮 market 为空: {$currentRoundSlug}");
        }

        $store->put(
            $cacheKey,
            $market,
            now()->addSeconds(max(60, (int) config('pm.tail_sweep_market_cache_ttl_seconds', 1800)))
        );

        return $market;
    }

    private function marketCacheKey(string $currentRoundSlug): string
    {
        return 'pm:tail_sweep:market:'.md5($currentRoundSlug);
    }

    private function resolveEntryPrice(
        PolymarketTradingService $trading,
        string $tokenId,
        string $side,
        string $targetUsdc,
        array &$books
    ): array {
        $bookKey = $tokenId.'|'.$side.'|'.$targetUsdc;
        if (!isset($books[$bookKey])) {
            try {
                $amount = bcdiv($targetUsdc, '1000000', 6);
                $books[$bookKey] = $trading->getOrderBookMarketPrice($tokenId, $side, $amount);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'No orderbook exists for the requested token id')) {
                    $books[$bookKey] = ['price' => '0', 'book' => []];
                } else {
                    throw $e;
                }
            }
        }

        return [(string) ($books[$bookKey]['price'] ?? '0'), 'orderbook_market_price'];
    }

    private function daemonLockKey(): string
    {
        return 'pm:tail_sweep_many:scan:run';
    }

    private function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('pm.tail_sweep_scan_cache_store');

        return $store !== null && $store !== '' ? Cache::store($store) : Cache::store();
    }

    private function getStartPrice(int $starTime, int $endTime, string $symbol): string
    {
        $symbol = strtoupper(trim((string) $symbol));
        if (str_contains($symbol, '/')) {
            $symbol = strtoupper((string) strstr($symbol, '/', true));
        }
        if ($symbol === '') {
            return '0';
        }

        $cacheKey = 'pm:tail_sweep:start_price:' . md5($symbol.'|'.$starTime.'|'.$endTime);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && preg_match('/^\d+(\.\d+)?$/', $cached) && bccomp($cached, '0', 8) > 0) {
            return $cached;
        }

        $eventStartTime = Carbon::createFromTimestamp((int) $starTime, 'UTC')->format('Y-m-d\TH:i:s\Z');
        $endDate = Carbon::createFromTimestamp((int) $endTime, 'UTC')->format('Y-m-d\TH:i:s\Z');

        $client = new Client([
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Mozilla/5.0',
            ],
        ]);

        $res = $client->get('https://polymarket.com/api/crypto/crypto-price', [
            'query' => [
                'symbol' => $symbol,
                'eventStartTime' => $eventStartTime,
                'variant' => 'fiveminute',
                'endDate' => $endDate,
            ],
        ]);

        $json = json_decode($res->getBody()->getContents(), true);
        if (!is_array($json)) {
            return '0';
        }

        $price = trim((string) ($json['openPrice'] ?? ''));
        if (preg_match('/^\d+(\.\d+)?$/', $price) && bccomp($price, '0', 8) > 0) {
            Cache::put($cacheKey, $price, now()->addMinutes(10));
            return $price;
        }

        return '0';
    }

    /**
     * 重连 Redis，避免长时间运行后连接超时
     */
    private function reconnectRedis(): void
    {
        try {
            // 测试 Cache Redis 连接是否正常（使用 ping 而不是 flush）
            Cache::getStore()->getRedis()->ping();
        } catch (\Throwable) {
            // 如果 ping 失败，说明连接已断开，强制重建连接池
            try {
                $app = app();
                $app->forgetInstance('cache');
                $app->forgetInstance('cache.store');
                Cache::purge();
            } catch (\Throwable) {
                // 忽略重建失败
            }
        }

        try {
            // 测试 Redis Facade 连接是否正常
            \Illuminate\Support\Facades\Redis::connection()->ping();
        } catch (\Throwable) {
            try {
                \Illuminate\Support\Facades\Redis::purge();
            } catch (\Throwable) {
                // 忽略重建失败
            }
        }
    }

    /**
     * 异步预检查所有钱包的授权状态
     * 在扫描开始前批量检查，后续下单时直接使用缓存
     */
    private function preCheckWalletReadiness($tasks, PolymarketTradingService $trading): void
    {
        // 收集所有需要检查的钱包（去重）
        $walletIds = $tasks->pluck('member_id')->unique()->filter();

        if ($walletIds->isEmpty()) {
            return;
        }

        $startTime = microtime(true);
        $checkedCount = 0;
        $cachedCount = 0;

        foreach ($walletIds as $memberId) {
            try {
                $wallet = \App\Models\Pm\PmCustodyWallet::where('member_id', $memberId)
                    ->with('member')
                    ->first();

                if (!$wallet) {
                    continue;
                }

                $side = 'BUY'; // 扫尾盘只做买入
                $cacheKey = 'wallet_readiness:' . $wallet->id . ':' . $side;

                // 检查缓存是否存在
                if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
                    $cachedCount++;
                    continue;
                }

                // 调用 API 检查授权状态
                $readiness = $trading->getTradingReadiness($wallet, $side, null, null, null);

                // 缓存结果5分钟
                if (($readiness['is_ready'] ?? false) === true) {
                    \Illuminate\Support\Facades\Cache::put($cacheKey, array_merge($readiness, [
                        'side' => $side,
                        'cached_at' => now()->toDateTimeString(),
                    ]), 300);
                    $checkedCount++;
                }
            } catch (\Throwable $e) {
                $this->warn("预检查钱包 {$memberId} 失败: {$e->getMessage()}");
            }
        }

        $elapsed = round((microtime(true) - $startTime) * 1000, 2);

        // if ($checkedCount > 0 || $cachedCount > 0) {
        //     $this->info("预检查完成: {$checkedCount} 个新检查, {$cachedCount} 个使用缓存, 耗时 {$elapsed}ms");
        // }
    }

    /**
     * 带 Redis 重试的操作包装器
     */
    private function withRedisRetry(callable $callback, int $maxRetries = 2)
    {
        $attempt = 0;
        while ($attempt < $maxRetries) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw $e;
                }

                // 检查是否是 Redis 连接错误
                if (str_contains($e->getMessage(), 'errno=10054') ||
                    str_contains($e->getMessage(), 'Connection lost') ||
                    str_contains($e->getMessage(), 'Redis::')) {
                    $this->warn("Redis 连接错误，尝试重连 (第 {$attempt} 次)");
                    $this->reconnectRedis();
                    sleep(1);
                } else {
                    throw $e;
                }
            }
        }
    }
}
