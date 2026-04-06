<?php

namespace App\Services\Pm\Sources;

use App\Services\DappPy\DappPyClient;
use App\Services\Pm\Contracts\LeaderTradeSourceInterface;

class FastApiLeaderTradeSource implements LeaderTradeSourceInterface
{
    public function __construct(private readonly DappPyClient $client)
    {
    }

    public function fetchTradesByUser(string $user, int $limit = 10, int $offset = 0): array
    {
        $response = $this->client->fetchLeaderTrades($user, $limit, $offset);
        $items = $response['items'] ?? [];

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }
}
