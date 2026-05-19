<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyWallet;
use Elliptic\EC;
use kornrunner\Keccak;
use SleepFinance\Eip712;

class PolymarketRelayerSignerService
{
    private const SAFE_FACTORY = '0xaacfeea03eb1561c4e67d661e40682bd20e3541b';
    private const PROXY_FACTORY = '0xab45c5a4b0c941a2f231c04c3f49182e1a254052';
    private const SAFE_INIT_CODE_HASH = '0x2bce2127ff07fb632d16c8347c4ebf501f4841168bed00d9e6ef715ddb6fcecf';
    private const PROXY_INIT_CODE_HASH = '0xd21df8dc65880a8606f09fe0ce3df9b8869287ab0b058be05aa9e8af6330a00b';
    private const RELAY_HUB = '0xd216153c06e857cd7f72665e0af1d7d82172f494';
    private const SAFE_MULTISEND = '0xa238cbeb142c10ef7ad8442c6d1f9e89e07e7761';

    public function __construct(
        private readonly PmPrivateKeyResolver $privateKeyResolver,
    ) {
    }

    public function resolveRelayerType(PmCustodyWallet $wallet): string
    {
        return (int) ($wallet->signature_type ?? 0) === 2 ? 'SAFE' : 'PROXY';
    }

    public function deriveProxyWallet(string $address): string
    {
        $address = strtolower($address);
        $salt = '0x' . Keccak::hash(hex2bin(substr($address, 2)), 256);
        return $this->create2(self::PROXY_FACTORY, $salt, self::PROXY_INIT_CODE_HASH);
    }

    public function deriveSafe(string $address): string
    {
        $address = strtolower($address);
        $encoded = str_pad(substr($address, 2), 64, '0', STR_PAD_LEFT);
        $salt = '0x' . Keccak::hash(hex2bin($encoded), 256);
        return $this->create2(self::SAFE_FACTORY, $salt, self::SAFE_INIT_CODE_HASH);
    }

    public function signSafeStructHash(PmCustodyWallet $wallet, array $payload): string
    {
        $typedData = [
            'types' => [
                'EIP712Domain' => [
                    ['name' => 'chainId', 'type' => 'uint256'],
                    ['name' => 'verifyingContract', 'type' => 'address'],
                ],
                'SafeTx' => [
                    ['name' => 'to', 'type' => 'address'],
                    ['name' => 'value', 'type' => 'uint256'],
                    ['name' => 'data', 'type' => 'bytes'],
                    ['name' => 'operation', 'type' => 'uint8'],
                    ['name' => 'safeTxGas', 'type' => 'uint256'],
                    ['name' => 'baseGas', 'type' => 'uint256'],
                    ['name' => 'gasPrice', 'type' => 'uint256'],
                    ['name' => 'gasToken', 'type' => 'address'],
                    ['name' => 'refundReceiver', 'type' => 'address'],
                    ['name' => 'nonce', 'type' => 'uint256'],
                ],
            ],
            'primaryType' => 'SafeTx',
            'domain' => [
                'chainId' => (int) config('pm.chain_id', 137),
                'verifyingContract' => strtolower((string) ($payload['proxyWallet'] ?? '')),
            ],
            'message' => [
                'to' => strtolower((string) ($payload['to'] ?? '')),
                'value' => (string) ($payload['value'] ?? '0'),
                'data' => strtolower((string) ($payload['data'] ?? '0x')),
                'operation' => (int) ($payload['operation'] ?? 0),
                'safeTxGas' => (string) ($payload['safeTxnGas'] ?? '0'),
                'baseGas' => (string) ($payload['baseGas'] ?? '0'),
                'gasPrice' => (string) ($payload['gasPrice'] ?? '0'),
                'gasToken' => strtolower((string) ($payload['gasToken'] ?? '0x0000000000000000000000000000000000000000')),
                'refundReceiver' => strtolower((string) ($payload['refundReceiver'] ?? '0x0000000000000000000000000000000000000000')),
                'nonce' => (string) ($payload['nonce'] ?? '0'),
            ],
        ];

        $hash = (new Eip712($typedData))->hashTypedDataV4();
        $signature = $this->signPersonalHash($wallet, $hash);

        return $this->packSafeSignature($signature);
    }

    public function signProxyHash(PmCustodyWallet $wallet, array $payload): string
    {
        $message = '0x726c783a'
            . str_pad(substr(strtolower((string) $payload['from']), 2), 40, '0', STR_PAD_LEFT)
            . str_pad(substr(strtolower((string) $payload['to']), 2), 40, '0', STR_PAD_LEFT)
            . ltrim(strtolower((string) $payload['data']), '0x')
            . str_pad($this->decToHex((string) ($payload['relayerFee'] ?? '0')), 64, '0', STR_PAD_LEFT)
            . str_pad($this->decToHex((string) ($payload['gasPrice'] ?? '0')), 64, '0', STR_PAD_LEFT)
            . str_pad($this->decToHex((string) ($payload['gasLimit'] ?? '0')), 64, '0', STR_PAD_LEFT)
            . str_pad($this->decToHex((string) ($payload['nonce'] ?? '0')), 64, '0', STR_PAD_LEFT)
            . str_pad(substr(strtolower((string) ($payload['relayHub'] ?? self::RELAY_HUB)), 2), 40, '0', STR_PAD_LEFT)
            . str_pad(substr(strtolower((string) ($payload['relay'] ?? '0x0000000000000000000000000000000000000000')), 2), 40, '0', STR_PAD_LEFT);

        $hash = '0x' . Keccak::hash(hex2bin(substr($message, 2)), 256);

        return $this->signPersonalHash($wallet, $hash);
    }

    public function signPersonalHash(PmCustodyWallet $wallet, string $hash): string
    {
        $privateKey = strtolower(ltrim($this->privateKeyResolver->resolve($wallet), '0x'));
        $hex = ltrim(strtolower($hash), '0x');
        $message = hex2bin($hex);
        if ($message === false) {
            throw new \RuntimeException('无效的 hash hex');
        }

        $prefixedHash = Keccak::hash("\x19Ethereum Signed Message:\n" . strlen($message) . $message, 256);
        $ec = new EC('secp256k1');
        $keyPair = $ec->keyFromPrivate($privateKey);
        $signature = $keyPair->sign($prefixedHash, ['canonical' => true]);
        $r = str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT);
        $v = str_pad(dechex($signature->recoveryParam + 27), 2, '0', STR_PAD_LEFT);

        return '0x' . strtolower($r . $s . $v);
    }

    public function packSafeSignature(string $signature): string
    {
        $signature = strtolower(ltrim($signature, '0x'));
        $r = gmp_strval(gmp_init(substr($signature, 0, 64), 16), 10);
        $s = gmp_strval(gmp_init(substr($signature, 64, 64), 16), 10);
        $v = hexdec(substr($signature, 128, 2));
        if ($v === 0 || $v === 1) {
            $v += 31;
        } elseif ($v === 27 || $v === 28) {
            $v += 4;
        }

        return '0x'
            . str_pad(gmp_strval(gmp_init($r, 10), 16), 64, '0', STR_PAD_LEFT)
            . str_pad(gmp_strval(gmp_init($s, 10), 16), 64, '0', STR_PAD_LEFT)
            . str_pad(dechex($v), 2, '0', STR_PAD_LEFT);
    }

    public function proxyFactory(): string
    {
        return self::PROXY_FACTORY;
    }

    public function safeFactory(): string
    {
        return self::SAFE_FACTORY;
    }

    public function relayHub(): string
    {
        return self::RELAY_HUB;
    }

    public function safeMultisend(): string
    {
        return self::SAFE_MULTISEND;
    }

    private function create2(string $factory, string $salt, string $initCodeHash): string
    {
        $payload = 'ff'
            . substr(strtolower($factory), 2)
            . substr(strtolower($salt), 2)
            . substr(strtolower($initCodeHash), 2);
        return '0x' . substr(Keccak::hash(hex2bin($payload), 256), 24);
    }

    private function decToHex(string $decimal): string
    {
        $decimal = trim($decimal);
        if ($decimal === '' || $decimal === '0') {
            return '0';
        }

        $hex = '';
        while (bccomp($decimal, '0', 0) > 0) {
            $mod = (int) bcmod($decimal, '16');
            $hex = dechex($mod) . $hex;
            $decimal = bcdiv($decimal, '16', 0);
        }

        return $hex === '' ? '0' : $hex;
    }
}
