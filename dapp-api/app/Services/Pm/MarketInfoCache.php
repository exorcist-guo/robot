<?php

namespace App\Services\Pm;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class MarketInfoCache
{
    public function normalizeMarketId(?string $marketId): string
    {
        return trim((string) $marketId);
    }

    public function marketKey(?string $marketId): string
    {
        $normalized = $this->normalizeMarketId($marketId);
        $key = preg_replace('/[^a-zA-Z0-9]+/', '_', $normalized);

        return trim((string) $key, '_') ?: 'unknown_market';
    }

    public function snapshotKey(?string $marketId): string
    {
        return 'pm:market_info:snapshot:'.$this->marketKey($marketId);
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
            'market_id' => $this->normalizeMarketId((string) ($snapshot['market_id'] ?? '')),
            'event_type' => (string) ($snapshot['event_type'] ?? ''),
            'timestamp' => (int) ($snapshot['timestamp'] ?? 0),
            'received_at' => (int) ($snapshot['received_at'] ?? time()),
            'payload' => is_array($snapshot['payload'] ?? null) ? $snapshot['payload'] : [],
            'raw' => $snapshot['raw'] ?? null,
        ];

        if ($normalized['market_id'] === '') {
            throw new \InvalidArgumentException('market_id 不能为空');
        }

        $this->repository()->put(
            $this->snapshotKey($normalized['market_id']),
            $normalized,
            now()->addSeconds($this->snapshotTtlSeconds())
        );

        return $normalized;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getSnapshot(?string $marketId): ?array
    {
        $snapshot = $this->repository()->get($this->snapshotKey($marketId));

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
     * @param array<int,string> $marketIds
     * @return array<int,string>
     */
    public function putSubscribedMarkets(array $marketIds): array
    {
        $marketIds = $this->normalizeMarketIds($marketIds);
        $this->repository()->put(
            $this->subscribedMarketsKey(),
            $marketIds,
            now()->addSeconds($this->metadataTtlSeconds())
        );

        return $marketIds;
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

        $payload['markets'] = $this->normalizeMarketIds(is_array($payload['markets']) ? $payload['markets'] : []);

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

            $marketId = $this->normalizeMarketId((string) ($market['market_id'] ?? ''));
            if ($marketId === '') {
                continue;
            }

            $normalized[$marketId] = [
                'market_id' => $marketId,
                'label' => trim((string) ($market['label'] ?? '')),
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @param array<int,string> $marketIds
     * @return array<int,string>
     */
    private function normalizeMarketIds(array $marketIds): array
    {
        $normalized = [];
        foreach ($marketIds as $marketId) {
            $marketId = $this->normalizeMarketId($marketId);
            if ($marketId === '') {
                continue;
            }
            $normalized[] = $marketId;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }
}
