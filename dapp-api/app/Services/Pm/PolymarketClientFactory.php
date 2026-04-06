<?php

namespace App\Services\Pm;

use PolymarketPhp\Polymarket\Auth\ApiCredentials;
use PolymarketPhp\Polymarket\Auth\ClobAuthenticator;
use PolymarketPhp\Polymarket\Auth\Signer\Eip712Signer as ClobAuthEip712Signer;
use PolymarketPhp\Polymarket\Client;
use PolymarketPhp\Polymarket\Clob;

class PolymarketClientFactory
{
    public function makeReadClient(): Client
    {
        return new Client(null, [
            'gamma_base_url' => config('pm.gamma_base_url'),
            'clob_base_url' => config('pm.clob_base_url'),
            'chain_id' => (int) config('pm.chain_id', 137),
            'timeout' => (int) config('pm.http_timeout', 90),
            'connect_timeout' => (int) config('pm.http_connect_timeout', 15),
        ]);
    }


    public function makeAuthedClobCL1lient(string $apikey, $config = []): Clob
    {
        $client = new Client($apikey, array_merge([
            'gamma_base_url' => config('pm.gamma_base_url'),
            'clob_base_url' => config('pm.clob_base_url'),
            'chain_id' => (int) config('pm.chain_id', 137),
            'timeout' => (int) config('pm.http_timeout', 90),
            'connect_timeout' => (int) config('pm.http_connect_timeout', 15),
        ], $config));

        return $client->clob();
    }


    public function makeAuthedClobClient(string $privateKey, ApiCredentials $credentials): Client
    {
        $config = [
            'gamma_base_url' => config('pm.gamma_base_url'),
            'clob_base_url' => config('pm.clob_base_url'),
            'private_key' => $privateKey,
            'chain_id' => (int) config('pm.chain_id', 137),
            'timeout' => (int) config('pm.http_timeout', 90),
            'connect_timeout' => (int) config('pm.http_connect_timeout', 15),
        ];

        $client = new Client(null, $config);

        $signer = new ClobAuthEip712Signer($privateKey, (int) config('pm.chain_id', 137));
        $auth = new ClobAuthenticator(
            $signer,
            (string) config('pm.clob_base_url'),
            (int) config('pm.chain_id', 137),
            $credentials
        );

        $client->clob()->auth($auth);

        return $client;
    }
}
