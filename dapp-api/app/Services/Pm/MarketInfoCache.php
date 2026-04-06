<?php

namespace App\Services\Pm;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class MarketInfoCache
{
    public function normalizeAssetId(?string $assetId): string
    {
        return trim((string) $assetId);
    }

    public function normalizeMarketId(?string $marketId): string
    {
        return $this->normalizeAssetId($marketId);
    }

    public function assetKey(?string $assetId): string
    {
        $normalized = $this->normalizeAssetId($assetId);
        $key = preg_replace('/[^a-zA-Z0-9]+/', '_', $normalized);

        return trim((string) $key, '_') ?: 'unknown_asset';
    }

    public function marketKey(?string $marketId): string
    {
        return $this->assetKey($marketId);
    }

    public function snapshotKey(?string $assetId): string
    {
        return 'pm:market_info:snapshot:'.$this->assetKey($assetId);
    }

    public function heartbeatKey(): string
    {
        return 'pm:market_info_daemon:heartbeat';
    }

    public function desiredMarketsKey(): string
    {
        return 'pm:market_info_daemon:desired_markets';
    }

    public function subscribedMarketsKey(): string
    {
        return 'pm:market_info_daemon:subscribed_markets';
    }

    public function daemonLockKey(): string
    {
        return 'pm:market_info_daemon:run';
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function putSnapshot(array $snapshot): array
    {
        $normalized = [
            'asset_id' => $this->normalizeAssetId((string) ($snapshot['asset_id'] ?? $snapshot['market_id'] ?? '')),
            'market_id' => $this->normalizeAssetId((string) ($snapshot['asset_id'] ?? $snapshot['market_id'] ?? '')),
            'event_type' => (string) ($snapshot['event_type'] ?? ''),
            'timestamp' => (int) ($snapshot['timestamp'] ?? 0),
            'received_at' => (int) ($snapshot['received_at'] ?? time()),
            'best_bid' => is_string($snapshot['best_bid'] ?? null) ? $snapshot['best_bid'] : null,
            'best_ask' => is_string($snapshot['best_ask'] ?? null) ? $snapshot['best_ask'] : null,
            'payload' => is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : [],
            'raw' => $snapshot['raw'] ?? null,
        ];

        if ($normalized['asset_id'] === '') {
            throw new \InvalidArgumentException('asset_id 不能为空');
        }

        $this->repository()->put(
            $this->snapshotKey($normalized['asset_id']),
            $normalized,
            now()->addSeconds($this->snapshotTtlSeconds())
        );

        return $normalized;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getSnapshot(?string $assetId): ?array
    {
        $snapshot = $this->repository()->get($this->snapshotKey($assetId));

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @param array<string,mixed>|null $snapshot
     */
    public function isFresh(?array $snapshot): bool
    {
        if (!is_array($snapshot)) {
            return false;
        }

        $receivedAt = (int) ($snapshot['received_at'] ?? 0);
        if ($receivedAt <= 0) {
            return false;
        }

        return (time() - $receivedAt) <= $this->staleAfterSeconds();
    }

    /**
     * @param array<int,array<string,mixed>> $markets
     * @return array<int,array<string,mixed>>
     */
    public function putDesiredMarkets(array $markets): array
    {
        $markets = $this->normalizeMarkets($markets);
        $this->repository()->put(
            $this->desiredMarketsKey(),
            $markets,
            now()->addSeconds($this->metadataTtlSeconds())
        );

        return $markets;
    }

    /**
     * @param array<int,string> $assetIds
     * @return array<int,string>
     */
    public function putSubscribedMarkets(array $assetIds): array
    {
        $assetIds = $this->normalizeAssetIds($assetIds);
        $this->repository()->put(
            $this->subscribedMarketsKey(),
            $assetIds,
            now()->addSeconds($this->metadataTtlSeconds())
        );

        return $assetIds;
    }

    /**
     * @param array<string,mixed> $heartbeat
     * @return array<string,mixed>
     */
    public function putHeartbeat(array $heartbeat): array
    {
        $payload = array_merge([
            'pid' => getmypid(),
            'updated_at' => time(),
            'connected' => false,
            'markets' => [],
        ], $heartbeat);

        $payload['markets'] = $this->normalizeAssetIds(is_array($payload['markets']) ? $payload['markets'] : []);

        $this->repository()->put(
            $this->heartbeatKey(),
            $payload,
            now()->addSeconds($this->heartbeatTtlSeconds())
        );

        return $payload;
    }

    public function staleAfterSeconds(): int
    {
        return max(1, (int) config('pm.market_info_stale_after_seconds', 20));
    }

    public function snapshotTtlSeconds(): int
    {
        return max($this->staleAfterSeconds(), (int) config('pm.market_info_snapshot_ttl_seconds', 120));
    }

    public function heartbeatTtlSeconds(): int
    {
        return max(10, (int) config('pm.market_info_daemon_heartbeat_ttl_seconds', 30));
    }

    public function metadataTtlSeconds(): int
    {
        return max($this->heartbeatTtlSeconds(), (int) config('pm.market_info_metadata_ttl_seconds', 300));
    }

    public function configuredStore(): ?string
    {
        $store = trim((string) config('pm.market_info_cache_store', ''));

        return $store !== '' ? $store : null;
    }

    public function driverName(): string
    {
        $store = $this->configuredStore() ?? (string) config('cache.default', 'file');

        return (string) config("cache.stores.{$store}.driver", $store);
    }

    private function repository(): Repository
    {
        $store = $this->configuredStore();

        return $store !== null ? Cache::store($store) : Cache::store();
    }

    /**
     * @param array<int,array<string,mixed>> $markets
     * @return array<int,array<string,mixed>>
     */
    private function normalizeMarkets(array $markets): array
    {
        $normalized = [];
        foreach ($markets as $market) {
            if (!is_array($market)) {
                continue;
            }

            $assetId = $this->normalizeAssetId((string) ($market['asset_id'] ?? $market['market_id'] ?? ''));
            if ($assetId === '') {
                continue;
            }

            $normalized[$assetId] = [
                'asset_id' => $assetId,
                'market_id' => $assetId,
                'label' => trim((string) ($market['label'] ?? '')),
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @param array<int,string> $assetIds
     * @return array<int,string>
     */
    private function normalizeAssetIds(array $assetIds): array
    {
        $normalized = [];
        foreach ($assetIds as $assetId) {
            $assetId = $this->normalizeAssetId($assetId);
            if ($assetId === '') {
                continue;
            }
            $normalized[] = $assetId;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }
}
