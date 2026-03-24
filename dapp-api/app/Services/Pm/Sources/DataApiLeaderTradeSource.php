<?php

namespace App\Services\Pm\Sources;

use App\Services\Pm\Contracts\LeaderTradeSourceInterface;
use App\Services\Pm\PolymarketDataClient;

class DataApiLeaderTradeSource implements LeaderTradeSourceInterface
{
    public function __construct(private readonly PolymarketDataClient $dataClient)
    {
    }

    public function fetchTradesByUser(string $user, int $limit = 10, int $offset = 0): array
    {
        return array_map(
            fn (array $trade) => $this->dataClient->normalizeTrade($trade),
            $this->dataClient->getTradesByUser($user, $limit, $offset)
        );
    }
}
