<?php

namespace App\Services\Pm;

class PolymarketMarketStream
{
    public function __construct(private readonly PolymarketDataClient $dataClient)
    {
    }

    /**
     * @param array<int,array<string,mixed>> $markets
     */
    public function connect(array $markets)
    {
        return $this->dataClient->openMarketInfoStream($markets);
    }

    /**
     * @param array<int,array<string,mixed>> $markets
     */
    public function subscribe($socket, array $markets): void
    {
        $this->dataClient->subscribeMarkets($socket, $markets);
    }

    /**
     * 读取一条原始 market websocket 消息。
     *
     * @return array<string,mixed>|null
     */
    public function readRawMessage($socket): ?array
    {
        return $this->dataClient->readMarketMessage($socket);
    }

    /**
     * 把原始 market 消息提取成统一快照结构。
     *
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null
     */
    public function extractSnapshot(array $message): ?array
    {
        return $this->dataClient->extractMarketInfoMessage($message);
    }

    /**
     * 读取一条实时消息，并过滤为市场快照。
     * 非目标消息会返回 null。
     *
     * @return array<string,mixed>|null
     */
    public function readMessage($socket): ?array
    {
        $message = $this->readRawMessage($socket);
        if (!is_array($message)) {
            return null;
        }

        return $this->extractSnapshot($message);
    }

    public function disconnect($socket): void
    {
        $this->dataClient->closeStream($socket);
    }
}
