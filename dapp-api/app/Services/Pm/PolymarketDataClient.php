<?php

namespace App\Services\Pm;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use GuzzleHttp\Client;

class PolymarketDataClient
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'base_uri' => 'https://data-api.polymarket.com/',
            'timeout' => 15,
        ]);
    }

    /**
     * 按用户地址拉取最近成交记录。
     *
     * @return array<int, array<string,mixed>>
     */
    public function getTradesByUser(string $user, int $limit = 50, int $offset = 0): array
    {
        $res = $this->client->get('trades', [
            'query' => [
                'user' => $user,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ]);

        $json = json_decode($res->getBody()->getContents(), true);
        return is_array($json) ? array_values($json) : [];
    }

    /**
     * 统一整理 trade 字段，适配后续 leader 跟单逻辑。
     *
     * @param array<string,mixed> $trade
     * @return array<string,mixed>
     */
    public function normalizeTrade(array $trade): array
    {
        $tradeId = (string) ($trade['id'] ?? $trade['trade_id'] ?? $trade['transactionHash'] ?? uniqid('trade_', true));
        $tokenId = (string) ($trade['asset_id'] ?? $trade['asset'] ?? $trade['token_id'] ?? $trade['tokenId'] ?? '');
        $marketId = (string) ($trade['market'] ?? $trade['market_id'] ?? $trade['conditionId'] ?? $trade['condition_id'] ?? '');
        $side = strtoupper((string) ($trade['side'] ?? 'BUY'));
        if ($side !== 'SELL') {
            $side = 'BUY';
        }

        $price = (string) ($trade['price'] ?? '0');
        $size = (string) ($trade['size'] ?? $trade['amount'] ?? $trade['shares'] ?? '0');
        $sizeUsdc = $this->toUsdcAtomic($price, $size);

        $time = $trade['time'] ?? $trade['timestamp'] ?? $trade['created_at'] ?? $trade['createdAt'] ?? now()->toIso8601String();

        return [
            'trade_id' => $tradeId,
            'market_id' => $marketId !== '' ? $marketId : null,
            'token_id' => $tokenId !== '' ? $tokenId : null,
            'side' => $side,
            'price' => $price,
            'size_usdc' => $sizeUsdc,
            'raw' => $trade,
            'traded_at' => $time,
        ];
    }

    // 把 price * size 转成 1e6 精度的 USDC 整数。
    public function toUsdcAtomic(string $price, string $size): int
    {
        $usdc = BigDecimal::of($price)
            ->multipliedBy(BigDecimal::of($size))
            ->multipliedBy('1000000')
            ->toScale(0, RoundingMode::DOWN);

        return (int) $usdc->__toString();
    }

    /**
     * 单次模式读取指定 symbol 的一条实时价格。
     * 主要用于兼容旧逻辑或调试；常驻 daemon 不走这个方法。
     *
     * @return array<string,mixed>
     */
    public function getChainlinkPriceSnapshot(string $symbol = 'btc/usd'): array
    {
        $symbol = $this->normalizeChainlinkSymbol($symbol);
        $socket = $this->openRtdsWebsocket();
        $this->subscribeChainlinkPrices($socket, [$symbol]);
        $deadline = microtime(true) + 8;

        try {
            while (microtime(true) < $deadline) {
                $message = $this->readRtdsMessage($socket);
                if (!is_array($message)) {
                    continue;
                }

                $snapshot = $this->extractChainlinkPriceMessage($message);
                if ($snapshot === null) {
                    continue;
                }

                if ($this->normalizeChainlinkSymbol((string) ($snapshot['symbol'] ?? '')) !== $symbol) {
                    continue;
                }

                return $snapshot;
            }
        } finally {
            fclose($socket);
        }

        throw new \RuntimeException('未收到 Chainlink 实时价格');
    }

    /**
     * 打开长连接并完成首批 symbol 订阅，供 daemon 复用。
     *
     * @param array<int,string> $symbols
     */
    public function openChainlinkPriceStream(array $symbols)
    {
        $socket = $this->openRtdsWebsocket();
        $this->subscribeChainlinkPrices($socket, $symbols);

        return $socket;
    }

    /**
     * 打开 market websocket 长连接并完成首批 market 订阅。
     *
     * @param array<int,array<string,mixed>> $markets
     */
    public function openMarketInfoStream(array $markets)
    {
        $socket = $this->openMarketWebsocket();
        $this->subscribeMarkets($socket, $markets);

        return $socket;
    }

    // 关闭长连接。
    public function closeStream($socket): void
    {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    // 标准化 Chainlink symbol，空值时默认回落到 btc/usd。
    public function normalizeChainlinkSymbol(string $symbol = 'btc/usd'): string
    {
        $symbol = strtolower(trim($symbol));

        return $symbol !== '' ? $symbol : 'btc/usd';
    }

    /**
     * 构造 WebSocket 订阅 payload，支持一次订阅多个 symbol。
     *
     * @param array<int,string> $symbols
     */
    public function buildChainlinkSubscribePayload(array $symbols): string
    {
        $symbols = array_values(array_unique(array_map(fn (string $symbol) => $this->normalizeChainlinkSymbol($symbol), $symbols)));
        if ($symbols === []) {
            throw new \RuntimeException('订阅 symbol 不能为空');
        }

        $subscriptions = [];
        foreach ($symbols as $symbol) {
            $subscriptions[] = [
                'topic' => 'crypto_prices_chainlink',
                'type' => '*',
                'filters' => json_encode(['symbol' => $symbol], JSON_UNESCAPED_SLASHES),
            ];
        }

        $payload = json_encode([
            'action' => 'subscribe',
            'subscriptions' => $subscriptions,
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($payload) || $payload === '') {
            throw new \RuntimeException('订阅消息构建失败');
        }

        return $payload;
    }

    /**
     * 在现有 socket 上发送行情订阅消息。
     *
     * @param array<int,string> $symbols
     */
    public function subscribeChainlinkPrices($socket, array $symbols): void
    {
        $this->writeWebsocketFrame($socket, $this->buildChainlinkSubscribePayload($symbols));
    }

    /**
     * 构造 market websocket 订阅 payload。
     *
     * @param array<int,array<string,mixed>> $markets
     */
    public function buildMarketSubscribePayload(array $markets): string
    {
        $assetsIds = [];
        foreach ($markets as $market) {
            if (!is_array($market)) {
                continue;
            }

            $marketId = trim((string) ($market['market_id'] ?? ''));
            if ($marketId === '') {
                continue;
            }

            $assetsIds[] = $marketId;
        }

        $assetsIds = array_values(array_unique($assetsIds));
        if ($assetsIds === []) {
            throw new \RuntimeException('订阅 market 不能为空');
        }

        $payload = json_encode([
            'assets_ids' => $assetsIds,
            'type' => 'market',
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($payload) || $payload === '') {
            throw new \RuntimeException('market 订阅消息构建失败');
        }

        return $payload;
    }

    /**
     * 在现有 socket 上发送 market 订阅消息。
     *
     * @param array<int,array<string,mixed>> $markets
     */
    public function subscribeMarkets($socket, array $markets): void
    {
        $this->writeWebsocketFrame($socket, $this->buildMarketSubscribePayload($markets));
    }

    // 建立到 Polymarket RTDS 的底层 websocket 连接并完成握手。
    public function openRtdsWebsocket()
    {
        return $this->openWebsocket(
            (string) config('pm.chainlink_rtds_host', 'ws-live-data.polymarket.com'),
            (int) config('pm.chainlink_rtds_port', 443),
            '/',
            (string) config('pm.chainlink_rtds_origin', 'https://polymarket.com'),
            max(1, (int) config('pm.chainlink_rtds_connect_timeout_seconds', 8)),
            max(1, (int) config('pm.chainlink_rtds_read_timeout_seconds', 8)),
        );
    }

    // 建立到 Polymarket market websocket 的底层连接并完成握手。
    public function openMarketWebsocket()
    {
        return $this->openWebsocket(
            (string) config('pm.market_ws_host', 'ws-subscriptions-clob.polymarket.com'),
            (int) config('pm.market_ws_port', 443),
            (string) config('pm.market_ws_path', '/ws/market'),
            (string) config('pm.market_ws_origin', 'https://polymarket.com'),
            max(1, (int) config('pm.market_ws_connect_timeout_seconds', 8)),
            max(1, (int) config('pm.market_ws_read_timeout_seconds', 8)),
        );
    }

    /**
     * 从 RTDS 读一帧并解码成数组。
     * 若发生读超时或连接关闭，会抛异常交给上层重连。
     *
     * @return array<string,mixed>|null
     */
    public function readRtdsMessage($socket): ?array
    {
        return $this->readWebsocketJsonMessage($socket, 'RTDS');
    }

    /**
     * 从 market websocket 读一帧并解码成数组。
     *
     * @return array<string,mixed>|null
     */
    public function readMarketMessage($socket): ?array
    {
        return $this->readWebsocketJsonMessage($socket, 'market websocket');
    }

    /**
     * 把原始 RTDS 消息过滤并提取为统一的行情快照结构。
     * 非 Chainlink 行情消息返回 null。
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null
     */
    public function extractChainlinkPriceMessage(array $message): ?array
    {
        if (($message['topic'] ?? null) !== 'crypto_prices_chainlink') {
            return null;
        }

        $payloadData = $message['payload'] ?? null;
        if (!is_array($payloadData)) {
            return null;
        }

        $value = $payloadData['value'] ?? null;
        if (!is_numeric($value)) {
            return null;
        }

        return [
            'symbol' => $this->normalizeChainlinkSymbol((string) ($payloadData['symbol'] ?? 'btc/usd')),
            'value' => (string) $value,
            'timestamp' => (int) ($payloadData['timestamp'] ?? $message['timestamp'] ?? 0),
            'raw' => $message,
        ];
    }

    /**
     * 把 market websocket 消息提取为统一的市场快照结构。
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null
     */
    public function extractMarketInfoMessage(array $message): ?array
    {
        $eventType = (string) ($message['event_type'] ?? $message['type'] ?? $message['event'] ?? '');
        $payload = $message['payload'] ?? null;
        if (!is_array($payload)) {
            $payload = $message;
        }

        $marketId = trim((string) (
            $payload['market_id']
            ?? $payload['market']
            ?? $payload['asset_id']
            ?? $payload['condition_id']
            ?? $message['market_id']
            ?? $message['market']
            ?? $message['asset_id']
            ?? $message['condition_id']
            ?? ''
        ));

        if ($marketId === '') {
            return null;
        }

        return [
            'market_id' => $marketId,
            'event_type' => $eventType,
            'timestamp' => (int) ($payload['timestamp'] ?? $message['timestamp'] ?? 0),
            'payload' => $payload,
            'raw' => $message,
        ];
    }

    // 建立通用 websocket 连接并完成握手。
    private function openWebsocket(string $host, int $port, string $path, string $origin, int $connectTimeout, int $readTimeout)
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $socket = @stream_socket_client('tls://'.$host.':'.$port, $errno, $errstr, $connectTimeout, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new \RuntimeException('连接 WebSocket 失败: '.($errstr ?: (string) $errno));
        }

        stream_set_timeout($socket, $readTimeout);
        $key = base64_encode(random_bytes(16));
        $headers = [
            'GET '.$path.' HTTP/1.1',
            "Host: {$host}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: '.$key,
            'Sec-WebSocket-Version: 13',
            "Origin: {$origin}",
            "\r\n",
        ];

        fwrite($socket, implode("\r\n", $headers));
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($socket, 1024);
            if ($chunk === false || $chunk === '') {
                fclose($socket);
                throw new \RuntimeException('WebSocket 握手失败');
            }
            $response .= $chunk;
        }

        return $socket;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readWebsocketJsonMessage($socket, string $sourceLabel): ?array
    {
        $frame = $this->readWebsocketFrame($socket);
        if ($frame === null || $frame === '') {
            $meta = is_resource($socket) ? stream_get_meta_data($socket) : [];
            if (($meta['timed_out'] ?? false) === true) {
                throw new \RuntimeException($sourceLabel.' 读取超时');
            }
            if (($meta['eof'] ?? false) === true) {
                throw new \RuntimeException($sourceLabel.' 连接已关闭');
            }

            return null;
        }

        $message = json_decode($frame, true);

        return is_array($message) ? $message : null;
    }

    // 按 websocket 协议把文本 payload 打包成客户端帧后写入 socket。
    private function writeWebsocketFrame($socket, string $payload): void
    {
        $length = strlen($payload);
        $frame = chr(0x81);
        $mask = random_bytes(4);

        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 65535) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($socket, $frame . $mask . $masked);
    }

    // 读取并解码一帧 websocket 消息，只返回 payload 内容。
    private function readWebsocketFrame($socket): ?string
    {
        $header = fread($socket, 2);
        if ($header === false || strlen($header) < 2) {
            return null;
        }

        $first = ord($header[0]);
        $second = ord($header[1]);
        $opcode = $first & 0x0f;
        $masked = ($second & 0x80) === 0x80;
        $length = $second & 0x7f;

        if ($opcode === 0x8) {
            return null;
        }

        if ($length === 126) {
            $extended = fread($socket, 2);
            if ($extended === false || strlen($extended) < 2) {
                return null;
            }
            $length = unpack('n', $extended)[1];
        } elseif ($length === 127) {
            $extended = fread($socket, 8);
            if ($extended === false || strlen($extended) < 8) {
                return null;
            }
            $parts = unpack('N2', $extended);
            $length = ($parts[1] << 32) | $parts[2];
        }

        $mask = '';
        if ($masked) {
            $mask = fread($socket, 4);
            if ($mask === false || strlen($mask) < 4) {
                return null;
            }
        }

        $payload = '';
        while (strlen($payload) < $length) {
            $chunk = fread($socket, $length - strlen($payload));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $payload .= $chunk;
        }

        if ($masked) {
            $decoded = '';
            for ($i = 0; $i < strlen($payload); $i++) {
                $decoded .= $payload[$i] ^ $mask[$i % 4];
            }
            $payload = $decoded;
        }

        return $payload;
    }
}
