<?php

namespace App\Services\Pm;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class TailSweepPriceCache
{
    // 统一标准化 symbol，避免 btc/usd、BTC/USD、带空格等写成不同 key。
    public function normalizeSymbol(?string $symbol): string
    {
        $symbol = strtolower(trim((string) $symbol));

        return $symbol !== '' ? $symbol : 'btc/usd';
    }

    // 把 symbol 转成适合拼接 cache key 的格式，例如 btc/usd -> btc_usd。
    public function symbolKey(?string $symbol): string
    {
        $normalized = $this->normalizeSymbol($symbol);
        $key = preg_replace('/[^a-z0-9]+/', '_', $normalized);

        return trim((string) $key, '_') ?: 'btc_usd';
    }

    // 单个 symbol 的最新行情快照 key。
    public function snapshotKey(?string $symbol): string
    {
        return 'pm:tail_sweep:price:snapshot:'.$this->symbolKey($symbol);
    }

    // daemon 心跳 key，用于观察进程是否活着。
    public function heartbeatKey(): string
    {
        return 'pm:tail_sweep:price_daemon:heartbeat';
    }

    // 当前期望订阅的 symbol 列表 key。
    public function desiredSymbolsKey(): string
    {
        return 'pm:tail_sweep:price_daemon:desired_symbols';
    }

    // 当前已成功发起订阅的 symbol 列表 key。
    public function subscribedSymbolsKey(): string
    {
        return 'pm:tail_sweep:price_daemon:subscribed_symbols';
    }

    // daemon 单实例锁 key。
    public function daemonLockKey(): string
    {
        return 'pm:tail_sweep:price_daemon:run';
    }

    /**
     * 归一化并写入最新行情快照。
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function putSnapshot(array $snapshot): array
    {
        var_dump($snapshot['symbol']);
        $normalized = [
            'symbol' => $this->normalizeSymbol((string) ($snapshot['symbol'] ?? '')),
            'value' => (string) ($snapshot['value'] ?? '0'),
            'timestamp' => (int) ($snapshot['timestamp'] ?? 0),
            'received_at' => (int) ($snapshot['received_at'] ?? time()),
            'raw' => $snapshot['raw'] ?? null,
        ];

        $this->repository()->put(
            $this->snapshotKey($normalized['symbol']),
            $normalized,
            now()->addSeconds($this->snapshotTtlSeconds())
        );

        return $normalized;
    }

    /**
     * 读取指定 symbol 的最新快照。
     *
     * @return array<string,mixed>|null
     */
    public function getSnapshot(?string $symbol): ?array
    {
        $snapshot = $this->repository()->get($this->snapshotKey($symbol));

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * 判断快照是否仍然新鲜。
     * 这里优先用本地收到消息的 received_at，而不是上游 timestamp。
     *
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
     * 写入当前期望订阅的 symbol 列表。
     *
     * @param array<int,string> $symbols
     * @return array<int,string>
     */
    public function putDesiredSymbols(array $symbols): array
    {
        $symbols = $this->normalizeSymbols($symbols);
        $this->repository()->put(
            $this->desiredSymbolsKey(),
            $symbols,
            now()->addSeconds($this->metadataTtlSeconds())
        );

        return $symbols;
    }

    /**
     * 写入当前已订阅的 symbol 列表。
     *
     * @param array<int,string> $symbols
     * @return array<int,string>
     */
    public function putSubscribedSymbols(array $symbols): array
    {
        $symbols = $this->normalizeSymbols($symbols);
        $this->repository()->put(
            $this->subscribedSymbolsKey(),
            $symbols,
            now()->addSeconds($this->metadataTtlSeconds())
        );

        return $symbols;
    }

    /**
     * 写入 daemon 心跳与状态信息。
     *
     * @param array<string,mixed> $heartbeat
     * @return array<string,mixed>
     */
    public function putHeartbeat(array $heartbeat): array
    {
        $payload = array_merge([
            'pid' => getmypid(),
            'updated_at' => time(),
            'connected' => false,
            'symbols' => [],
        ], $heartbeat);

        $payload['symbols'] = $this->normalizeSymbols(is_array($payload['symbols']) ? $payload['symbols'] : []);

        $this->repository()->put(
            $this->heartbeatKey(),
            $payload,
            now()->addSeconds($this->heartbeatTtlSeconds())
        );

        return $payload;
    }

    // 快照在业务上允许的最大陈旧秒数。
    public function staleAfterSeconds(): int
    {
        return max(1, (int) config('pm.tail_sweep_price_stale_after_seconds', 12));
    }

    // 快照在缓存中的保留时长；通常大于 stale 阈值，便于排障查看旧值。
    public function snapshotTtlSeconds(): int
    {
        return max($this->staleAfterSeconds(), (int) config('pm.tail_sweep_price_snapshot_ttl_seconds', 120));
    }

    // 心跳 key 的 TTL。
    public function heartbeatTtlSeconds(): int
    {
        return max(10, (int) config('pm.tail_sweep_price_daemon_heartbeat_ttl_seconds', 30));
    }

    // desired/subscribed 元数据的 TTL。
    public function metadataTtlSeconds(): int
    {
        return max($this->heartbeatTtlSeconds(), (int) config('pm.tail_sweep_price_metadata_ttl_seconds', 300));
    }

    // 若配置了专用 cache store，则返回其名称；否则使用默认 store。
    public function configuredStore(): ?string
    {
        $store = trim((string) config('pm.tail_sweep_price_cache_store', ''));

        return $store !== '' ? $store : null;
    }

    // 读取当前 cache store 的 driver 名，用于判断是否为 redis。
    public function driverName(): string
    {
        $store = $this->configuredStore() ?? (string) config('cache.default', 'file');

        return (string) config("cache.stores.{$store}.driver", $store);
    }

    // 统一拿 Repository，避免外部每处都自己判断 store。
    private function repository(): Repository
    {
        $store = $this->configuredStore();

        return $store !== null ? Cache::store($store) : Cache::store();
    }

    /**
     * 统一归一化 symbol 列表：格式化、去空、去重、排序。
     *
     * @param array<int,string> $symbols
     * @return array<int,string>
     */
    private function normalizeSymbols(array $symbols): array
    {
        $normalized = [];
        foreach ($symbols as $symbol) {
            $normalized[] = $this->normalizeSymbol($symbol);
        }

        $normalized = array_values(array_unique(array_filter($normalized, static fn (string $value) => $value !== '')));
        sort($normalized);

        return $normalized;
    }
}
