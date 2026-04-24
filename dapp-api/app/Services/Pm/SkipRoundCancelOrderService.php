<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyWallet;

class SkipRoundCancelOrderService
{
    public function __construct(
        private readonly PolymarketTradingService $trading,
        private readonly PmPrivateKeyResolver $privateKeyResolver,
        private readonly PolymarketClientFactory $factory,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function cancel(PmCustodyWallet $wallet, string $remoteOrderId): array
    {
        $credRecord = $wallet->apiCredentials ?: $this->trading->ensureApiCredentials($wallet);
        $creds = $this->trading->decodeApiCredentials($credRecord);
        $privateKey = $this->privateKeyResolver->resolve($wallet);
        $client = $this->factory->makeAuthedClobClient($privateKey, $creds);

        return $client->clob()->orders()->cancel([
            'orderID' => $remoteOrderId,
        ]);
    }
}
