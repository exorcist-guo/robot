<?php

namespace App\Services\Pm;

use GuzzleHttp\Client;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class TailSweepMarketDataService
{
    public function normalizeBaseSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        return (string) (preg_replace('/-\d{10}$/', '', $slug) ?: $slug);
    }

    public function buildCurrentRoundSlug(string $baseSlug, Carbon $now): string
    {
        $roundStart = str_contains($baseSlug, '15m')
            ? $this->getRoundStartTime15($now)
            : $this->getRoundStartTime($now);

        return $this->buildRoundSlug($baseSlug, $roundStart);
    }

    public function buildRoundSlug(string $baseSlug, int $roundStart): string
    {
        return $baseSlug.'-'.$roundStart;
    }

    public function getRoundStartTime(Carbon $now): int
    {
        $minutes = (int) $now->format('i');
        $targetMinutes = (int) (floor($minutes / 5) * 5);

        return strtotime($now->format('Y-m-d H:').sprintf('%02d', $targetMinutes).':00');
    }

    public function getRoundStartTime15(Carbon $now): int
    {
        $minutes = (int) $now->format('i');
        $targetMinutes = (int) (floor($minutes / 15) * 15);

        return strtotime($now->format('Y-m-d H:').sprintf('%02d', $targetMinutes).':00');
    }

    public function getRoundEndTime(Carbon $now): int
    {
        return $this->getRoundStartTime($now) + 300;
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveCurrentRoundMarket(GammaClient $gammaClient, string $currentRoundSlug): array
    {
        return $this->resolveMarketBySlug($gammaClient, $currentRoundSlug);
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveMarketBySlug(GammaClient $gammaClient, string $roundSlug): array
    {
        $store = $this->cacheStore();
        $cacheKey = $this->marketCacheKey($roundSlug);
        $cached = $store->get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $market = $gammaClient->resolveTailSweepMarket($roundSlug);
        if (!is_array($market) || $market === []) {
            throw new \RuntimeException("当前轮 market 为空: {$roundSlug}");
        }

        $store->put(
            $cacheKey,
            $market,
            now()->addSeconds(max(60, (int) config('pm.tail_sweep_market_cache_ttl_seconds', 1800)))
        );

        return $market;
    }

    /**
     * @param array<string,mixed> $books
     * @return array{0:string,1:string,2:bool|null}
     */
    public function resolveEntryPrice(
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
                    $books[$bookKey] = ['price' => '0', 'book' => [], 'depth_reached' => null];
                } else {
                    throw $e;
                }
            }
        }

        return [
            (string) ($books[$bookKey]['price'] ?? '0'),
            'orderbook_market_price',
            isset($books[$bookKey]['depth_reached']) ? (bool) $books[$bookKey]['depth_reached'] : null,
        ];
    }

    public function getStartPrice(int $startTime, int $endTime, string $symbol): string
    {
        $symbol = strtoupper(trim((string) $symbol));
        if (str_contains($symbol, '/')) {
            $symbol = strtoupper((string) strstr($symbol, '/', true));
        }
        if ($symbol === '') {
            return '0';
        }

        $cacheKey = 'pm:tail_sweep:start_price:'.md5($symbol.'|'.$startTime.'|'.$endTime);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && preg_match('/^\d+(\.\d+)?$/', $cached) && bccomp($cached, '0', 8) > 0) {
            return $cached;
        }

        $eventStartTime = Carbon::createFromTimestamp((int) $startTime, 'UTC')->format('Y-m-d\TH:i:s\Z');
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

    private function marketCacheKey(string $currentRoundSlug): string
    {
        return 'pm:tail_sweep:market:'.md5($currentRoundSlug);
    }

    private function cacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('pm.tail_sweep_scan_cache_store');

        return $store !== null && $store !== '' ? Cache::store($store) : Cache::store();
    }
}
