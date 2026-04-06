<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCopyTask;
use App\Services\Pm\PolymarketClientFactory;

class MarketInfoRegistry
{
    public function __construct(
        private readonly MarketInfoCache $cache,
        private readonly PolymarketClientFactory $factory,
    ) {
    }

    /**
     * 从扫尾盘任务里提取基础 slug，自动拼出当前轮 slug，
     * 再通过 Gamma 查询当前轮 market，提取真正需要订阅的 id。
     *
     * @return array<int,array<string,mixed>>
     */
    public function desiredMarkets(): array
    {
        $baseSlugs = PmCopyTask::query()
            ->where('status', 1)
            ->where('mode', PmCopyTask::MODE_TAIL_SWEEP)
            ->whereNotNull('market_slug')
            ->select('market_slug')
            ->groupBy('market_slug')
            ->pluck('market_slug')
            ->all();

        if ($baseSlugs === []) {
            return $this->cache->putDesiredMarkets([]);
        }

        $gammaClient = $this->factory->makeReadClient();
        $markets = [];

        foreach ($baseSlugs as $baseSlug) {
            $baseSlug = trim((string) $baseSlug);
            if ($baseSlug === '') {
                continue;
            }

            $currentSlug = $this->buildCurrentRoundSlug($baseSlug);

            try {
                $market = $gammaClient->gamma()->markets()->getBySlug($currentSlug);
            } catch (\Throwable) {
                continue;
            }

            if (!is_array($market) || $market === []) {
                continue;
            }

            foreach ($this->extractAssetIds($market) as $assetId) {
                $markets[] = [
                    'asset_id' => $assetId,
                    'label' => $currentSlug,
                ];
            }
        }

        return $this->cache->putDesiredMarkets($markets);
    }

    private function buildCurrentRoundSlug(string $baseSlug): string
    {
        $now = time();
        $minutes = (int) date('i', $now);
        $targetMinutes = (int) (floor($minutes / 5) * 5);
        $timestamp = strtotime(date('Y-m-d H:', $now).sprintf('%02d', $targetMinutes).':00');

        return $baseSlug.'-'.$timestamp;
    }

    /**
     * @param array<string,mixed> $market
     * @return array<int,string>
     */
    private function extractAssetIds(array $market): array
    {
        $raw = $market['clobTokenIds'] ?? [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $assetIds = [];
        foreach ($raw as $assetId) {
            $assetId = $this->cache->normalizeMarketId((string) $assetId);
            if ($assetId === '') {
                continue;
            }
            $assetIds[] = $assetId;
        }

        $assetIds = array_values(array_unique($assetIds));
        sort($assetIds);

        return $assetIds;
    }
}
