<?php

namespace App\Services\DappPy;

use GuzzleHttp\Client;

class DappPyClient
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'base_uri' => rtrim((string) config('dapp_py.base_url'), '/') . '/',
            'timeout' => (int) config('dapp_py.timeout', 15),
            'connect_timeout' => (int) config('dapp_py.connect_timeout', 5),
            'headers' => [
                'Accept' => 'application/json',
                'X-Internal-Token' => (string) config('dapp_py.internal_token', ''),
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function health(): array
    {
        return $this->postGetJson('internal/health', [], 'GET');
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchLeaderTrades(string $user, int $limit = 10, int $offset = 0): array
    {
        return $this->postGetJson('internal/leader-trades/fetch', [
            'user' => $user,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function compareLeaderTrades(string $user, int $limit = 10, int $offset = 0): array
    {
        return $this->postGetJson('internal/leader-trades/compare', [
            'user' => $user,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function postGetJson(string $uri, array $payload = [], string $method = 'POST'): array
    {
        $options = $method === 'GET' ? [] : ['json' => $payload];
        $response = $this->client->request($method, $uri, $options);
        $json = json_decode($response->getBody()->getContents(), true);

        return is_array($json) ? $json : [];
    }
}
