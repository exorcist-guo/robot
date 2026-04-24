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
        return array_map(
            // 把 Data API 原始 trade 结构转换成系统内部统一的成交字段格式。
            fn (array $trade) => $this->dataClient->normalizeTrade($trade),
            // 拉取指定用户的成交列表；默认取最新 10 条。
            $this->dataClient->getTradesByUser($user, $limit, $offset)
        );
    }
}
