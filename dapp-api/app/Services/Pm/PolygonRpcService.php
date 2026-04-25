<?php

namespace App\Services\Pm;

use GuzzleHttp\Client;
use RuntimeException;

class PolygonRpcService
{
    private Client $client;

    public function __construct(?string $rpcUrl = null)
    {
        $rpcUrl ??= trim((string) config('pm.polygon_rpc_url'));
        if ($rpcUrl === '') {
            throw new RuntimeException('PM_POLYGON_RPC_URL 未配置');
        }

        $this->client = new Client([
            'base_uri' => rtrim($rpcUrl, '/') . '/',
            'timeout' => 20,
        ]);
    }

    public function call(string $method, array $params): mixed
    {
        $response = $this->client->post('', [
            'json' => [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => 1,
            ],
        ]);

        $json = json_decode($response->getBody()->getContents(), true);
        if (!is_array($json)) {
            throw new RuntimeException('Polygon RPC 响应格式错误');
        }

        if (!empty($json['error'])) {
            $message = is_array($json['error']) ? ($json['error']['message'] ?? 'Polygon RPC 调用失败') : (string) $json['error'];
            throw new RuntimeException((string) $message);
        }

        return $json['result'] ?? null;
    }

    public function getBlockNumber(): int
    {
        return $this->rpcQuantityToInt($this->call('eth_blockNumber', []));
    }

    public function getBlockByNumber(int|string $blockNumber, bool $fullTransactions = false): ?array
    {
        $tag = is_int($blockNumber) ? $this->toRpcHex($blockNumber) : $blockNumber;
        $result = $this->call('eth_getBlockByNumber', [$tag, $fullTransactions]);
        return is_array($result) ? $result : null;
    }

    /**
     * @param array<string,mixed> $filter
     * @return array<int,array<string,mixed>>
     */
    public function getLogs(array $filter): array
    {
        $result = $this->call('eth_getLogs', [$filter]);
        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    public function getTransactionCount(string $address, string $tag = 'pending'): int
    {
        return $this->rpcQuantityToInt($this->call('eth_getTransactionCount', [strtolower($address), $tag]));
    }

    public function gasPrice(): string
    {
        return (string) $this->call('eth_gasPrice', []);
    }

    public function estimateGas(array $transaction, string $tag = 'latest'): int
    {
        return $this->rpcQuantityToInt($this->call('eth_estimateGas', [$transaction, $tag]));
    }

    public function sendRawTransaction(string $signedTx): string
    {
        return (string) $this->call('eth_sendRawTransaction', [$signedTx]);
    }

    public function getTransactionReceipt(string $txHash): ?array
    {
        $result = $this->call('eth_getTransactionReceipt', [$txHash]);
        return is_array($result) ? $result : null;
    }

    public function receiptStatusSucceeded(?array $receipt): bool
    {
        return $this->normalizeReceiptStatus($receipt['status'] ?? null) === 1;
    }

    public function normalizeReceiptStatus(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^0x[0-9a-f]+$/i', $value) === 1) {
            return (int) hexdec(substr($value, 2));
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    public function rpcQuantityToInt(mixed $value): int
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }

        return (int) hexdec(str_starts_with($value, '0x') ? substr($value, 2) : $value);
    }

    public function toRpcHex(int $value): string
    {
        return '0x' . dechex($value);
    }
}
