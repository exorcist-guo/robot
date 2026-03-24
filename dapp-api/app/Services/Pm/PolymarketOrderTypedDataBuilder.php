<?php

namespace App\Services\Pm;

use PolymarketPhp\Polymarket\Enums\SignatureType;

class PolymarketOrderTypedDataBuilder
{
    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function build(array $order): array
    {
        return [
            'types' => [
                'EIP712Domain' => [
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'version', 'type' => 'string'],
                    ['name' => 'chainId', 'type' => 'uint256'],
                    ['name' => 'verifyingContract', 'type' => 'address'],
                ],
                'Order' => [
                    ['name' => 'salt', 'type' => 'uint256'],
                    ['name' => 'maker', 'type' => 'address'],
                    ['name' => 'signer', 'type' => 'address'],
                    ['name' => 'taker', 'type' => 'address'],
                    ['name' => 'tokenId', 'type' => 'uint256'],
                    ['name' => 'makerAmount', 'type' => 'uint256'],
                    ['name' => 'takerAmount', 'type' => 'uint256'],
                    ['name' => 'expiration', 'type' => 'uint256'],
                    ['name' => 'nonce', 'type' => 'uint256'],
                    ['name' => 'feeRateBps', 'type' => 'uint256'],
                    ['name' => 'side', 'type' => 'uint8'],
                    ['name' => 'signatureType', 'type' => 'uint8'],
                ],
            ],
            'primaryType' => 'Order',
            'domain' => [
                'name' => 'Polymarket CTF Exchange',
                'version' => '1',
                'chainId' => (int) config('pm.chain_id', 137),
                'verifyingContract' => (string) config('pm.exchange_contract'),
            ],
            'message' => [
                'salt' => (string) $order['salt'],
                'maker' => strtolower((string) $order['maker']),
                'signer' => strtolower((string) $order['signer']),
                'taker' => strtolower((string) ($order['taker'] ?? '0x0000000000000000000000000000000000000000')),
                'tokenId' => (string) $order['tokenId'],
                'makerAmount' => (string) $order['makerAmount'],
                'takerAmount' => (string) $order['takerAmount'],
                'expiration' => (string) ($order['expiration'] ?? '0'),
                'nonce' => (string) ($order['nonce'] ?? '0'),
                'feeRateBps' => (string) ($order['feeRateBps'] ?? '0'),
                'side' => (int) $order['side'],
                'signatureType' => (int) ($order['signatureType'] ?? SignatureType::EOA->value),
            ],
        ];
    }
}
