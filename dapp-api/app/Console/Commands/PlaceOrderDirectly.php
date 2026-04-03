<?php

namespace App\Console\Commands;

use App\Jobs\PmExecuteOrderIntentJob;
use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\GammaClient;
use App\Services\Pm\MarketInfoCache;
use App\Services\Pm\PolymarketTradingService;
use App\Services\Pm\TailSweepPriceCache;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PlaceOrderDirectly extends Command
{

    protected $signature = 'place-order-directly';

    protected $description = '不判断条件,直接根据涨跌自动下单';

    public function handle(
        GammaClient $gammaClient,
        TailSweepPriceCache $priceCache,
        PolymarketTradingService $trading,
        MarketInfoCache $marketInfoCache
    )
    {

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



        $this->scan($gammaClient, $priceCache, $trading, $marketInfoCache);
    }

    private function scan(
        GammaClient $gammaClient,
        TailSweepPriceCache $priceCache,
        PolymarketTradingService $trading,
        MarketInfoCache $marketInfoCache
    ): void
    {
        // 固定本轮扫描时间基准，避免循环内多次 now() 导致边界判断漂移。
        $now = now();

        // 只加载启用中的扫尾盘任务，并裁剪为本轮计算真正需要的字段。
        $tasks = PmCopyTask::query()
            ->where('mode', PmCopyTask::MODE_TAIL_SWEEP)
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
            ]);

        // 同一轮扫描内按 symbol / token+side 做缓存，避免重复请求外部数据。
        $snapshots = [];
        $books = [];

        foreach ($tasks as $task) {
            $baseSlug = $this->normalizeBaseSlug((string) $task->market_slug);
            if ($baseSlug !== '') {
                $currentRoundSlug = $this->buildCurrentRoundSlug($baseSlug, $now);
                var_dump($currentRoundSlug);
                // $gammaClient->test($currentRoundSlug);exit;
                $starTime = $this->starTime($now);
                $endTime = $starTime + 300;
                $market_end_at = date('Y-m-d H:i:s',$endTime);//每5分钟一个轮次
                $needsRefresh = !$task->market_end_at || (string) $task->market_end_at->timestamp !== (string) $endTime;

                if ($needsRefresh) {

                    try {
                        $market = $this->resolveCurrentRoundMarket($gammaClient, $currentRoundSlug);
                        // var_dump($market);
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
                var_dump(6666);
                continue;
            }

            $remainingSeconds = $now->diffInSeconds($marketEndAt, false);


            // 达到累计亏损停单阈值后，自动暂停任务并记录停单时间。
            if ($task->tail_loss_stop_count > 0 && $task->tail_loss_count >= $task->tail_loss_stop_count) {
                if ($task->status !== 0 || $task->tail_loss_stopped_at === null) {
                    $task->status = 0;
                    $task->tail_loss_stopped_at = $now;
                    $task->save();
                }
                var_dump(8888);
                continue;
            }



            // 默认标的是 btc/usd；同一 symbol 在本轮扫描内只读取一次共享缓存。
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
                var_dump(99999);
                continue;
            }

            // currentPrice 是实时价格；tail_round_started_value 改为本轮开始价格。
            $currentPrice = (string) ($snapshot['value'] ?? '0');
            if (!preg_match('/^\d+(\.\d+)?$/', $currentPrice)) {
                var_dump(7777);
                continue;
            }

            // 以 end_at 时间戳作为轮次 key，同一轮只允许触发一次。
            $roundKey = (string) $marketEndAt->timestamp;
            // var_dump($roundKey);exit;
            // if ($task->tail_round_started_value === null) {
            //     var_dump(99999);
            //     $task->tail_round_started_value = $currentPrice;
            //     $task->save();
            // }


            $startPrice = $this->getStartPrice($starTime,$endTime,$symbol);


            // 变化量 = 当前价格 - 本轮开始价格。
            $change = bcsub($currentPrice, $startPrice, 8);
            $threshold = (string) ($task->tail_trigger_amount ?: '100000000');

            if (!preg_match('/^\d+(\.\d+)?$/', $threshold) || bccomp($threshold, '0', 8) <= 0) {
                continue;
            }

            $side = null;
            $tokenId = null;
            $triggerSide = null;
            //判断价格限制是否满足
            // if(abs($change) > $threshold){
                if ($change > 0 ) {
                    // 涨幅达到阈值：买上涨方向 token。
                    $side = PolymarketTradingService::SIDE_BUY;
                    $tokenId = (string) $task->token_yes_id;
                    $triggerSide = 'up';
                    var_dump(2222);
                } elseif ($change < 0) {
                    // 跌幅达到阈值：买下跌方向 token。
                    $side = PolymarketTradingService::SIDE_BUY;
                    $tokenId = (string) $task->token_no_id;
                    $triggerSide = 'down';
                    var_dump(3333);
                }
            // }




            if (!$side || !$triggerSide || $tokenId === '') {
                var_dump(!$side,!$triggerSide,$tokenId);
                continue;
            }

            if (!$trading->isTokenTradable($tokenId)) {
                var_dump([
                    'task_id' => $task->id,
                    'current_round_slug' => $currentRoundSlug ?? '',
                    'market_id' => (string) $task->market_id,
                    'market_question' => (string) $task->market_question,
                    'trigger_side' => $triggerSide,
                    'token_id' => $tokenId,
                    'token_yes_id' => (string) $task->token_yes_id,
                    'token_no_id' => (string) $task->token_no_id,
                ]);
                $this->warn("任务 {$task->id} token 不可交易: {$tokenId}");
                continue;
            }

            // 优先使用 market websocket 缓存里的 best_ask 作为买入参考价；取不到再回退到订单簿最优价。
            [$entryPrice, $entryPriceSource] = $this->resolveEntryPrice($marketInfoCache, $trading, $tokenId, $side, $books);
            if (!preg_match('/^\d+(\.\d+)?$/', $entryPrice) || bccomp($entryPrice, '0', 8) <= 0) {
                var_dump($entryPrice,$entryPriceSource,44444);
                continue;
            }

            // 同一任务同一轮若已有 pending 意图，则不再重复创建。
            $existingIntent = PmOrderIntent::query()
                ->where('copy_task_id', $task->id)
                ->where('status', PmOrderIntent::STATUS_PENDING)
                ->where('risk_snapshot->round_key', $roundKey)
                ->first();
            if ($existingIntent) {
                var_dump(5555);
                continue;
            }

            // 创建待执行下单意图，并把本轮触发上下文完整写入 risk_snapshot。
            $intent = PmOrderIntent::create([
                'copy_task_id' => $task->id,
                'leader_trade_id' => null,
                'member_id' => $task->member_id,
                'token_id' => $tokenId,
                'side' => $side,
                'leader_price' => $entryPrice,
                'target_usdc' => (int) $task->tail_order_usdc,
                'clamped_usdc' => (int) $task->tail_order_usdc,
                'status' => PmOrderIntent::STATUS_PENDING,
                'skip_reason' => null,
                'risk_snapshot' => [
                    'mode' => PmCopyTask::MODE_TAIL_SWEEP,
                    'max_slippage_bps' => $task->max_slippage_bps,
                    'allow_partial_fill' => (bool) $task->allow_partial_fill,
                    'daily_max_usdc' => $task->daily_max_usdc,
                    'round_key' => $roundKey,
                    'market_slug' => $task->market_slug,
                    'market_id' => $task->market_id,
                    'market_question' => $task->market_question,
                    'resolution_source' => $task->resolution_source,
                    'trigger_side' => $triggerSide,
                    'trigger_amount' => $threshold,
                    'current_price' => $currentPrice,
                    'round_start_price' => $startPrice,
                    'price_to_beat' => (string) ($task->price_to_beat ?: '0'),
                    'change' => $change,
                    'remaining_seconds' => $remainingSeconds,
                    'token_yes_id' => $task->token_yes_id,
                    'token_no_id' => $task->token_no_id,
                    'entry_price' => $entryPrice,
                    'entry_price_source' => $entryPriceSource,
                ],
            ]);

            // 标记当前轮次已触发，防止本轮再次生成意图。
            $task->tail_last_triggered_round_key = $roundKey;
            $task->save();

            // 交给统一下单执行任务异步处理。
            PmExecuteOrderIntentJob::dispatchSync($intent->id);
            $intent->refresh();
            $order = $intent->order()->latest('id')->first();
            var_dump([
                'task_id' => $task->id,
                'intent_id' => $intent->id,
                'intent_status' => (int) $intent->status,
                'intent_skip_reason' => (string) ($intent->skip_reason ?? ''),
                'intent_last_error_code' => (string) ($intent->last_error_code ?? ''),
                'intent_last_error_message' => (string) ($intent->last_error_message ?? ''),
                'order_id' => $order?->id,
                'order_status' => $order ? (int) $order->status : null,
                'order_error_code' => $order ? (string) ($order->error_code ?? '') : '',
                'order_error_message' => $order ? (string) ($order->error_message ?? '') : '',
                'poly_order_id' => $order ? (string) ($order->poly_order_id ?? '') : '',
            ]);
            $this->info("任务 {$task->id} 已触发扫尾盘下单");
        }
    }

    private function buildCurrentRoundSlug(string $baseSlug, Carbon $now): string
    {
        $minutes = (int) $now->format('i');
        $targetMinutes = (int) (floor($minutes / 5) * 5);
        $timestamp = strtotime($now->format('Y-m-d H:').sprintf('%02d', $targetMinutes).':00');

        return $baseSlug.'-'.$timestamp;
    }

    private function starTime( Carbon $now): string
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
        $cached = ''; //临时取消缓存
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
        MarketInfoCache $marketInfoCache,
        PolymarketTradingService $trading,
        string $tokenId,
        string $side,
        array &$books
    ): array {
        if ($side === PolymarketTradingService::SIDE_BUY) {
            $marketSnapshot = $marketInfoCache->getSnapshot($tokenId);
            if ($marketInfoCache->isFresh($marketSnapshot)) {
                $bestAsk = (string) ($marketSnapshot['best_ask'] ?? '');
                if (preg_match('/^\d+(\.\d+)?$/', $bestAsk) && bccomp($bestAsk, '0', 8) > 0) {
                    return [$bestAsk, 'market_cache_best_ask'];
                }
            }
        }

        $bookKey = $tokenId.'|'.$side;
        if (!isset($books[$bookKey])) {
            try {
                $books[$bookKey] = $trading->getOrderBookBestPrice($tokenId, $side);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'No orderbook exists for the requested token id')) {
                    $books[$bookKey] = ['price' => '0', 'book' => []];
                } else {
                    throw $e;
                }
            }
        }

        return [(string) ($books[$bookKey]['price'] ?? '0'), 'orderbook_best_price'];
    }

    private function daemonLockKey(): string
    {
        return 'pm:tail_sweep:scan:run';
    }

    private function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('pm.tail_sweep_scan_cache_store');

        return $store !== null && $store !== '' ? Cache::store($store) : Cache::store();
    }

    //获取开始时间
    private function getStartPrice($starTime,$endTime,$symbol){
        //请求示例:https://polymarket.com/api/crypto/crypto-price?symbol=BTC&eventStartTime=2026-03-26T01:30:00Z&variant=fiveminute&endDate=2026-03-26T01:35:00Z
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
}
