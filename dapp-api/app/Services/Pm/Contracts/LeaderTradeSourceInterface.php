<?php

namespace App\Services\Pm\Contracts;

interface LeaderTradeSourceInterface
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function fetchTradesByUser(string $user, int $limit = 10, int $offset = 0): array;
}
