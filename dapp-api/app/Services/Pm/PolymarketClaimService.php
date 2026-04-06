<?php

namespace App\Services\Pm;

use App\Models\Pm\PmOrder;
use EthTool\Credential;
use GuzzleHttp\Client;
use Illuminate\Support\Str;

class PolymarketClaimService
{
    private const REDEEM_POSITIONS_SELECTOR = '0x01b7037c';
    private const ZERO_BYTES32 = '0x0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private readonly PolymarketTradingService $trading,
        private readonly PmPrivateKeyResolver $privateKeyResolver,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function buildClaimPlan(PmOrder $order): array
    {
        $order->loadMissing('intent.copyTask.member.custodyWallet.apiCredentials');

        $wallet = $order->intent?->member?->custodyWallet;
        $conditionId = $this->resolveConditionId($order);
        $claimableUsdc = (int) ($order->claimable_usdc ?? 0);
        $indexSets = $this->resolveIndexSets($order);
        $calldata = null;

        if ($conditionId !== null) {
            $calldata = $this->encodeRedeemPositionsCalldata(
                (string) config('pm.collateral_token'),
                self::ZERO_BYTES32,
                $conditionId,
                $indexSets,
            );
        }

        return [
            'ready' => $wallet !== null && $conditionId !== null,
            'wallet_id' => $wallet?->id,
            'member_id' => $wallet?->member_id,
            'trading_address' => $wallet?->tradingAddress(),
            'chain_id' => (int) config('pm.chain_id', 137),
            'rpc_url_configured' => trim((string) config('pm.polygon_rpc_url')) !== '',
            'contract' => strtolower((string) config('pm.ctf_contract')),
            'method' => 'redeemPositions',
            'signature' => 'redeemPositions(address,bytes32,bytes32,uint256[])',
            'selector' => self::REDEEM_POSITIONS_SELECTOR,
            'params' => [
                'collateralToken' => strtolower((string) config('pm.collateral_token')),
                'parentCollectionId' => self::ZERO_BYTES32,
                'conditionId' => $conditionId,
                'indexSets' => $indexSets,
            ],
            'encoded_args' => $calldata !== null ? substr($calldata, 10) : null,
            'calldata' => $calldata,
            'claimable_usdc' => $claimableUsdc,
            'claimable_tokens' => $order->filled_size,
            'source' => [
                'condition_id' => $this->detectConditionIdSource($order),
                'index_sets' => 'market_tokens',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function claimOrderWinnings(PmOrder $order, bool $dryRun = false): array
    {
        $claimableUsdc = (int) ($order->claimable_usdc ?? 0);
        if ($claimableUsdc <= 0) {
            return [
                'submitted' => false,
                'already_claimed' => false,
                'claimable_usdc' => $claimableUsdc,
                'reason' => 'nothing_to_claim',
            ];
        }

        $plan = $this->buildClaimPlan($order);
        if (($plan['ready'] ?? false) !== true) {
            return $plan + [
                'submitted' => false,
                'already_claimed' => false,
                'reason' => 'missing_claim_context',
            ];
        }

        if ($dryRun) {
            return $plan + [
                'submitted' => false,
                'already_claimed' => false,
                'reason' => 'dry_run',
            ];
        }

        $wallet = $order->intent?->member?->custodyWallet;
        if (!$wallet) {
            throw new \RuntimeException('订单缺少托管钱包，无法兑奖');
        }

        $rpcUrl = trim((string) config('pm.polygon_rpc_url'));
        if ($rpcUrl === '') {
            throw new \RuntimeException('PM_POLYGON_RPC_URL 未配置');
        }

        $privateKey = $this->privateKeyResolver->resolve($wallet);
        $credential = Credential::fromKey(ltrim($privateKey, '0x'));
        $from = strtolower($credential->getAddress());
        $nonce = $this->rpcQuantityToInt($this->rpc($rpcUrl, 'eth_getTransactionCount', [$from, 'pending']));
        $gasPriceHex = (string) $this->rpc($rpcUrl, 'eth_gasPrice', []);

        // 增加20%的Gas Price安全边际，避免交易卡在mempool
        $baseGasPrice = $this->rpcQuantityToInt($gasPriceHex);
        $safeGasPrice = (int) ($baseGasPrice * 1.2);
        $gasPriceHex = '0x' . dechex($safeGasPrice);

        $chainId = (int) config('pm.chain_id', 137);
        $gasLimit = 220000;

        $raw = [
            'nonce' => $this->toRpcHex($nonce),
            'gasPrice' => $gasPriceHex,
            'gasLimit' => $this->toRpcHex($gasLimit),
            'to' => (string) $plan['contract'],
            'value' => $this->toRpcHex(0),
            'data' => (string) $plan['calldata'],
            'chainId' => $chainId,
        ];

        $signed = $credential->signTransaction($raw);
        $txHash = (string) $this->rpc($rpcUrl, 'eth_sendRawTransaction', [$signed]);

        return $plan + [
            'submitted' => true,
            'already_claimed' => false,
            'reason' => 'submitted',
            'tx_hash' => $txHash,
            'from' => $from,
            'nonce' => $nonce,
            'gas_limit' => $gasLimit,
            'gas_price' => $gasPriceHex,
            'raw' => [
                'to' => $raw['to'],
                'value' => $raw['value'],
                'data' => $raw['data'],
                'chainId' => $raw['chainId'],
            ],
        ];
    }

    /**
     * 等待交易确认
     *
     * @param string $txHash 交易哈希
     * @param int $timeoutSeconds 超时时间（秒）
     * @return bool 是否确认成功
     */
    public function waitForTransactionConfirmation(string $txHash, int $timeoutSeconds = 30): bool
    {
        $rpcUrl = trim((string) config('pm.polygon_rpc_url'));
        if ($rpcUrl === '') {
            return false;
        }

        $startTime = time();
        $maxAttempts = $timeoutSeconds;

        for ($i = 0; $i < $maxAttempts; $i++) {
            if (time() - $startTime >= $timeoutSeconds) {
                break;
            }

            try {
                $receipt = $this->getTransactionReceipt($txHash);
                if ($receipt !== null) {
                    // status = 0x1 表示成功，0x0 表示失败
                    return ($receipt['status'] ?? '0x0') === '0x1';
                }
            } catch (\Exception $e) {
                // 忽略查询错误，继续重试
            }

            sleep(1);
        }

        return false;
    }

    /**
     * 获取交易回执
     *
     * @param string $txHash 交易哈希
     * @return array|null
     */
    public function getTransactionReceipt(string $txHash): ?array
    {
        $rpcUrl = trim((string) config('pm.polygon_rpc_url'));
        if ($rpcUrl === '') {
            return null;
        }

        try {
            $result = $this->rpc($rpcUrl, 'eth_getTransactionReceipt', [$txHash]);
            return is_array($result) ? $result : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function encodeRedeemPositionsCalldata(string $collateralToken, string $parentCollectionId, string $conditionId, array $indexSets): string
    {
        $head = '';
        $head .= $this->encodeAddress($collateralToken);
        $head .= $this->encodeBytes32($parentCollectionId);
        $head .= $this->encodeBytes32($conditionId);
        $head .= $this->encodeUint256(128);

        $tail = $this->encodeUint256(count($indexSets));
        foreach ($indexSets as $indexSet) {
            $tail .= $this->encodeUint256($indexSet);
        }

        return self::REDEEM_POSITIONS_SELECTOR . $head . $tail;
    }

    /**
     * @return array<int,int>
     */
    private function resolveIndexSets(PmOrder $order): array
    {
        $tokens = $order->settlement_payload['market']['tokens'] ?? [];
        if (is_array($tokens) && $tokens !== []) {
            $indexSets = [];
            foreach (array_values($tokens) as $index => $token) {
                if (is_array($token)) {
                    $indexSets[] = 1 << $index;
                }
            }
            if ($indexSets !== []) {
                return $indexSets;
            }
        }

        return [1, 2];
    }

    private function resolveConditionId(PmOrder $order): ?string
    {
        $candidates = [
            $order->settlement_payload['condition_id'] ?? null,
            $order->settlement_payload['remote_order']['market'] ?? null,
            $order->settlement_payload['market']['conditionId'] ?? null,
            $order->settlement_payload['market']['condition_id'] ?? null,
            $order->response_payload['market'] ?? null,
            $order->intent?->risk_snapshot['market_id'] ?? null,
            $order->intent?->copyTask?->market_id ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^0x[a-fA-F0-9]{64}$/', $candidate) === 1) {
                return strtolower($candidate);
            }
        }

        return null;
    }

    private function detectConditionIdSource(PmOrder $order): ?string
    {
        $sources = [
            'settlement_payload.condition_id' => $order->settlement_payload['condition_id'] ?? null,
            'settlement_payload.remote_order.market' => $order->settlement_payload['remote_order']['market'] ?? null,
            'settlement_payload.market.conditionId' => $order->settlement_payload['market']['conditionId'] ?? null,
            'settlement_payload.market.condition_id' => $order->settlement_payload['market']['condition_id'] ?? null,
            'response_payload.market' => $order->response_payload['market'] ?? null,
            'intent.risk_snapshot.market_id' => $order->intent?->risk_snapshot['market_id'] ?? null,
            'copy_task.market_id' => $order->intent?->copyTask?->market_id ?? null,
        ];

        foreach ($sources as $source => $value) {
            if (is_string($value) && preg_match('/^0x[a-fA-F0-9]{64}$/', $value) === 1) {
                return $source;
            }
        }

        return null;
    }

    private function encodeAddress(string $value): string
    {
        return str_pad(Str::lower(ltrim($value, '0x')), 64, '0', STR_PAD_LEFT);
    }

    private function encodeBytes32(string $value): string
    {
        return str_pad(Str::lower(ltrim($value, '0x')), 64, '0', STR_PAD_LEFT);
    }

    private function encodeUint256(int $value): string
    {
        return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<int,mixed> $params
     */
    private function rpc(string $url, string $method, array $params): mixed
    {
        $response = (new Client([
            'base_uri' => rtrim($url, '/') . '/',
            'timeout' => 20,
        ]))->post('', [
            'json' => [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => 1,
            ],
        ]);

        $json = json_decode($response->getBody()->getContents(), true);
        if (!is_array($json)) {
            throw new \RuntimeException('Polygon RPC 响应格式错误');
        }

        if (!empty($json['error'])) {
            $message = is_array($json['error']) ? ($json['error']['message'] ?? 'Polygon RPC 调用失败') : (string) $json['error'];
            throw new \RuntimeException((string) $message);
        }

        return $json['result'] ?? null;
    }

    private function rpcQuantityToInt(mixed $value): int
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }

        return (int) hexdec(str_starts_with($value, '0x') ? substr($value, 2) : $value);
    }

    private function toRpcHex(int $value): string
    {
        return '0x' . dechex($value);
    }
}
