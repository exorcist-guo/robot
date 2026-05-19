<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyWallet;
use GuzzleHttp\Client;

class PolymarketGaslessClaimService
{
    public function __construct(
        private readonly PolymarketClaimService $claimService,
        private readonly PolymarketRelayerSignerService $relayerSigner,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $positions
     * @return array<string,mixed>
     */
    public function claimPositions(PmCustodyWallet $wallet, array $positions, int $timeoutSeconds = 30): array
    {
        $plan = $this->claimService->buildClaimPlanFromPositions($wallet, $positions);
        if (($plan['ready'] ?? false) !== true) {
            return $plan + [
                'success' => false,
                'submitted' => false,
                'provider' => 'gasless',
                'error_code' => 'missing_claim_context',
                'error_message' => 'missing_claim_context',
                'retryable' => false,
                'should_fallback' => false,
            ];
        }

        $builderApiKey = trim((string) config('pm.builder_api_key', ''));
        $builderSecret = trim((string) config('pm.builder_api_secret', ''));
        $builderPassphrase = trim((string) config('pm.builder_api_passphrase', ''));
        $relayerApiKey = trim((string) config('pm.relayer_api_key', ''));
        $relayerApiKeyAddress = strtolower(trim((string) config('pm.relayer_api_key_address', '')));

        if ($builderApiKey === '' || $builderSecret === '' || $builderPassphrase === '') {
            if ($relayerApiKey === '' || $relayerApiKeyAddress === '') {
                return $plan + [
                    'success' => false,
                    'submitted' => false,
                    'provider' => 'gasless',
                    'error_code' => 'gasless_not_configured',
                    'error_message' => '缺少 builder/relayer 鉴权配置',
                    'retryable' => false,
                    'should_fallback' => true,
                ];
            }
        }

        try {
            $request = $this->buildRelayerRequest($wallet, $positions, $plan);
        } catch (\Throwable $e) {
            return $plan + [
                'success' => false,
                'submitted' => false,
                'provider' => 'gasless',
                'error_code' => 'build_request_failed',
                'error_message' => $e->getMessage(),
                'retryable' => false,
                'should_fallback' => true,
            ];
        }

        $path = '/submit';
        $body = json_encode($request, JSON_UNESCAPED_SLASHES);
        if (!is_string($body) || $body === '') {
            return $plan + [
                'success' => false,
                'submitted' => false,
                'provider' => 'gasless',
                'error_code' => 'encode_request_failed',
                'error_message' => '无法编码 relayer request',
                'retryable' => false,
                'should_fallback' => true,
            ];
        }

        $headers = ['Content-Type' => 'application/json'];
        if ($builderApiKey !== '' && $builderSecret !== '' && $builderPassphrase !== '') {
            $timestamp = (string) time();
            $signature = $this->buildBuilderSignature($builderSecret, $timestamp, 'POST', $path, $body);
            $headers['POLY_BUILDER_API_KEY'] = $builderApiKey;
            $headers['POLY_BUILDER_TIMESTAMP'] = $timestamp;
            $headers['POLY_BUILDER_PASSPHRASE'] = $builderPassphrase;
            $headers['POLY_BUILDER_SIGNATURE'] = $signature;
        } else {
            $headers['RELAYER_API_KEY'] = $relayerApiKey;
            $headers['RELAYER_API_KEY_ADDRESS'] = $relayerApiKeyAddress;
        }

        $client = new Client([
            'base_uri' => rtrim((string) config('pm.gasless_base_url', 'https://relayer-v2.polymarket.com'), '/') . '/',
            'timeout' => max(1, $timeoutSeconds),
            'http_errors' => false,
        ]);

        $response = $client->post(ltrim($path, '/'), [
            'headers' => $headers,
            'body' => $body,
        ]);

        $status = $response->getStatusCode();
        $payload = json_decode($response->getBody()->getContents(), true);
        $payload = is_array($payload) ? $payload : [];

        if ($status < 200 || $status >= 300 || empty($payload['transactionID'])) {
            return $plan + [
                'success' => false,
                'submitted' => false,
                'provider' => 'gasless',
                'request_payload' => $request,
                'response_payload' => $payload,
                'error_code' => 'submit_failed',
                'error_message' => (string) ($payload['error'] ?? ('http_' . $status)),
                'retryable' => $status >= 500 || $status === 429,
                'should_fallback' => true,
            ];
        }

        $transactionId = (string) $payload['transactionID'];
        $transaction = $this->waitForTransaction($client, $transactionId, $timeoutSeconds);
        if ($transaction === null) {
            return $plan + [
                'success' => false,
                'submitted' => true,
                'provider' => 'gasless',
                'transaction_id' => $transactionId,
                'request_payload' => $request,
                'response_payload' => $payload,
                'error_code' => 'wait_timeout',
                'error_message' => 'relayer transaction wait timeout',
                'retryable' => true,
                'should_fallback' => true,
            ];
        }

        $state = (string) ($transaction['state'] ?? '');
        $success = in_array($state, ['STATE_MINED', 'STATE_CONFIRMED'], true);

        return $plan + [
            'success' => $success,
            'submitted' => true,
            'provider' => 'gasless',
            'transaction_id' => $transactionId,
            'tx_hash' => $transaction['transactionHash'] ?? null,
            'request_payload' => $request,
            'response_payload' => $payload,
            'transaction_payload' => $transaction,
            'error_code' => $success ? null : 'gasless_failed',
            'error_message' => $success ? null : ('state=' . $state),
            'retryable' => !$success,
            'should_fallback' => !$success,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $positions
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function buildRelayerRequest(PmCustodyWallet $wallet, array $positions, array $plan): array
    {
        $type = $this->relayerSigner->resolveRelayerType($wallet);
        $from = strtolower((string) $wallet->signer_address);
        $to = strtolower((string) ($plan['contract'] ?? ''));
        $data = strtolower((string) ($plan['calldata'] ?? ''));

        if ($type === 'SAFE') {
            $proxyWallet = strtolower((string) ($wallet->funder_address ?: $this->relayerSigner->deriveSafe($from)));
            $nonce = $this->getRelayerNonce($from, 'SAFE');
            $request = [
                'from' => $from,
                'to' => $to,
                'proxyWallet' => $proxyWallet,
                'data' => $data,
                'nonce' => $nonce,
                'signatureParams' => [
                    'gasPrice' => '0',
                    'operation' => '0',
                    'safeTxnGas' => '0',
                    'baseGas' => '0',
                    'gasToken' => '0x0000000000000000000000000000000000000000',
                    'refundReceiver' => '0x0000000000000000000000000000000000000000',
                ],
                'type' => 'SAFE',
                'metadata' => 'Redeem positions',
                'value' => '0',
            ];
            $request['signature'] = $this->relayerSigner->signSafeStructHash($wallet, [
                'proxyWallet' => $proxyWallet,
                'to' => $to,
                'value' => '0',
                'data' => $data,
                'operation' => 0,
                'safeTxnGas' => '0',
                'baseGas' => '0',
                'gasPrice' => '0',
                'gasToken' => '0x0000000000000000000000000000000000000000',
                'refundReceiver' => '0x0000000000000000000000000000000000000000',
                'nonce' => $nonce,
            ]);

            return $request;
        }

        $relayPayload = $this->getRelayPayload($from, 'PROXY');
        $proxyWallet = strtolower((string) ($wallet->funder_address ?: $this->relayerSigner->deriveProxyWallet($from)));
        $request = [
            'from' => $from,
            'to' => strtolower($this->relayerSigner->proxyFactory()),
            'proxyWallet' => $proxyWallet,
            'data' => $this->encodeProxyTransactionData($to, $data),
            'nonce' => (string) ($relayPayload['nonce'] ?? '0'),
            'signatureParams' => [
                'gasPrice' => '0',
                'gasLimit' => '10000000',
                'relayerFee' => '0',
                'relayHub' => strtolower($this->relayerSigner->relayHub()),
                'relay' => strtolower((string) ($relayPayload['address'] ?? '0x0000000000000000000000000000000000000000')),
            ],
            'type' => 'PROXY',
            'metadata' => 'Redeem positions',
            'value' => '0',
        ];
        $request['signature'] = $this->relayerSigner->signProxyHash($wallet, [
            'from' => $from,
            'to' => strtolower($this->relayerSigner->proxyFactory()),
            'data' => $request['data'],
            'relayerFee' => '0',
            'gasPrice' => '0',
            'gasLimit' => '10000000',
            'nonce' => (string) $request['nonce'],
            'relayHub' => strtolower($this->relayerSigner->relayHub()),
            'relay' => strtolower((string) ($relayPayload['address'] ?? '0x0000000000000000000000000000000000000000')),
        ]);

        return $request;
    }

    private function getRelayerNonce(string $address, string $type): string
    {
        $client = new Client([
            'base_uri' => rtrim((string) config('pm.gasless_base_url', 'https://relayer-v2.polymarket.com'), '/') . '/',
            'timeout' => max(1, (int) config('pm.gasless_timeout', 30)),
            'http_errors' => false,
        ]);
        $response = $client->get('nonce', [
            'query' => [
                'address' => strtolower($address),
                'type' => $type,
            ],
        ]);
        $payload = json_decode($response->getBody()->getContents(), true);
        return is_array($payload) && isset($payload['nonce']) ? (string) $payload['nonce'] : '0';
    }

    /**
     * @return array<string,mixed>
     */
    private function getRelayPayload(string $address, string $type): array
    {
        $client = new Client([
            'base_uri' => rtrim((string) config('pm.gasless_base_url', 'https://relayer-v2.polymarket.com'), '/') . '/',
            'timeout' => max(1, (int) config('pm.gasless_timeout', 30)),
            'http_errors' => false,
        ]);
        $response = $client->get('relay-payload', [
            'query' => [
                'address' => strtolower($address),
                'type' => $type,
            ],
        ]);
        $payload = json_decode($response->getBody()->getContents(), true);
        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function waitForTransaction(Client $client, string $transactionId, int $timeoutSeconds): ?array
    {
        $deadline = time() + max(1, $timeoutSeconds);
        while (time() < $deadline) {
            $response = $client->get('transaction', [
                'query' => ['id' => $transactionId],
            ]);
            $payload = json_decode($response->getBody()->getContents(), true);
            if (is_array($payload) && isset($payload[0]) && is_array($payload[0])) {
                $state = (string) ($payload[0]['state'] ?? '');
                if (in_array($state, ['STATE_MINED', 'STATE_CONFIRMED', 'STATE_FAILED', 'STATE_INVALID'], true)) {
                    return $payload[0];
                }
            }
            sleep(2);
        }

        return null;
    }

    private function buildBuilderSignature(string $secret, string $timestamp, string $method, string $path, ?string $body): string
    {
        $message = $timestamp . strtoupper($method) . $path . ($body ?? '');
        $decodedSecret = base64_decode($secret, true);
        if ($decodedSecret === false) {
            throw new \RuntimeException('builder secret 不是合法 base64');
        }
        $hmac = hash_hmac('sha256', $message, $decodedSecret, true);
        return strtr(base64_encode($hmac), '+/', '-_');
    }

    private function encodeProxyTransactionData(string $to, string $data): string
    {
        $selector = '0xa26d3a76';
        $tupleOffset = str_pad(dechex(32), 64, '0', STR_PAD_LEFT);
        $arrayLength = str_pad(dechex(1), 64, '0', STR_PAD_LEFT);
        $itemOffset = str_pad(dechex(32), 64, '0', STR_PAD_LEFT);
        $typeCode = str_pad(dechex(1), 64, '0', STR_PAD_LEFT);
        $toWord = str_pad(substr(strtolower($to), 2), 64, '0', STR_PAD_LEFT);
        $valueWord = str_pad('0', 64, '0', STR_PAD_LEFT);
        $dataOffset = str_pad(dechex(128), 64, '0', STR_PAD_LEFT);
        $dataHex = ltrim(strtolower($data), '0x');
        $dataLength = str_pad(dechex((int) (strlen($dataHex) / 2)), 64, '0', STR_PAD_LEFT);
        $dataPadded = str_pad($dataHex, (int) ceil(strlen($dataHex) / 64) * 64, '0', STR_PAD_RIGHT);

        return $selector
            . $tupleOffset
            . $arrayLength
            . $itemOffset
            . $typeCode
            . $toWord
            . $valueWord
            . $dataOffset
            . $dataLength
            . $dataPadded;
    }
}
