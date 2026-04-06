<?php

namespace App\Services\Pm;

class PolymarketChainlinkStream
{
    public function __construct(private readonly PolymarketDataClient $dataClient)
    {
    }

    /**
     * 建立长连接并完成首批 symbol 的订阅。
     *
     * @param array<int,string> $symbols
     */
    public function connect(array $symbols)
    {
        return $this->dataClient->openChainlinkPriceStream($symbols);
    }

    /**
     * 在已建立的连接上追加订阅新的 symbol。
     *
     * @param array<int,string> $symbols
     */
    public function subscribe($socket, array $symbols): void
    {
        $this->dataClient->subscribeChainlinkPrices($socket, $symbols);
    }

    /**
     * 读取一条实时消息，并过滤为 Chainlink 行情快照。
     * 非目标消息会返回 null。
     *
     * @return array<string,mixed>|null
     */
    public function readMessage($socket): ?array
    {
        $message = $this->dataClient->readRtdsMessage($socket);
        if (!is_array($message)) {
            return null;
        }

        return $this->dataClient->extractChainlinkPriceMessage($message);
    }

    // 主动关闭连接。
    public function disconnect($socket): void
    {
        $this->dataClient->closeStream($socket);
    }
}
