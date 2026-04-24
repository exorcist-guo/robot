<?php

namespace App\Services\Pm\Sources;

use App\Services\Pm\Contracts\LeaderTradeSourceInterface;
use App\Services\Pm\PolymarketDataClient;

class DataApiLeaderTradeSource implements LeaderTradeSourceInterface
{
    public function __construct(private readonly PolymarketDataClient $dataClient)
    {
    }

    /**
     * 从 Polymarket Data API 拉取某个用户的成交记录。
     *
     * 参数说明：
     * - $user   : leader 的钱包地址
     * - $limit  : 本次最多拉取多少条，默认 10 条
     * - $offset : 分页偏移量，0 表示从最新成交开始取
     *
     * 返回值说明：
     * - 先调用 dataClient->getTradesByUser() 获取原始接口数据
     * - 再逐条调用 normalizeTrade() 归一化字段结构
     * - 最终返回统一格式的成交数组，供上层轮询命令直接入库/派发跟单使用
     */
    public function fetchTradesByUser(string $user, int $limit = 10, int $offset = 0): array
    {
        $takerTrades = $this->dataClient->getTradesByUser($user, $limit, $offset, true);
        $allTrades = $this->dataClient->getTradesByUser($user, $limit, $offset, false);


        
        $takerTradeIds = array_flip(array_map(
            fn (array $trade) => (string) ($trade['id'] ?? $trade['trade_id'] ?? $trade['transactionHash'] ?? ''),
            array_filter($takerTrades, 'is_array')
        ));

        return array_map(function (array $trade) use ($takerTradeIds) {
            $tradeId = (string) ($trade['id'] ?? $trade['trade_id'] ?? $trade['transactionHash'] ?? '');
            $trade['leader_role'] = $tradeId !== '' && isset($takerTradeIds[$tradeId]) ? 'taker' : 'maker';

            return $this->dataClient->normalizeTrade($trade);
        }, $allTrades);
    }
}
