<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyWallet;
use App\Models\Pm\PmOrder;
use EthTool\Credential;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class PolymarketClaimService
{
    private const REDEEM_POSITIONS_SELECTOR = '0x01b7037c';
    private const NEG_RISK_REDEEM_POSITIONS_SELECTOR = '0xdbeccb23';
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
        $isNegativeRisk = $this->isNegativeRiskOrder($order);
        $calldata = null;

        if ($conditionId !== null) {
            $calldata = $isNegativeRisk
                ? $this->encodeNegRiskRedeemCalldata($order, $conditionId)
                : $this->encodeRedeemPositionsCalldata(
                    (string) config('pm.claim_collateral_token', config('pm.collateral_token')),
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
            'contract' => strtolower((string) ($isNegativeRisk ? config('pm.neg_risk_adapter_contract') : config('pm.ctf_contract'))),
            'method' => 'redeemPositions',
            'signature' => $isNegativeRisk
                ? 'redeemPositions(bytes32,uint256[])'
                : 'redeemPositions(address,bytes32,bytes32,uint256[])',
            'selector' => $isNegativeRisk ? self::NEG_RISK_REDEEM_POSITIONS_SELECTOR : self::REDEEM_POSITIONS_SELECTOR,
            'params' => $isNegativeRisk
                ? [
                    'conditionId' => $conditionId,
                    'amounts' => $this->resolveNegRiskRedeemAmounts($order),
                ]
                : [
                    'collateralToken' => strtolower((string) config('pm.claim_collateral_token', config('pm.collateral_token'))),
                    'parentCollectionId' => self::ZERO_BYTES32,
                    'conditionId' => $conditionId,
                    'indexSets' => $indexSets,
                ],
            'encoded_args' => $calldata !== null ? substr($calldata, 10) : null,
            'calldata' => $calldata,
            'claimable_usdc' => $claimableUsdc,
            'claimable_tokens' => $order->filled_size,
            'negative_risk' => $isNegativeRisk,
            'source' => [
                'condition_id' => $this->detectConditionIdSource($order),
                'index_sets' => $isNegativeRisk ? 'neg_risk_amounts' : 'market_tokens',
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
        $gasLimit = $this->resolveGasLimit($rpcUrl, [
            'from' => $from,
            'to' => (string) $plan['contract'],
            'value' => $this->toRpcHex(0),
            'data' => (string) $plan['calldata'],
        ], (bool) ($plan['negative_risk'] ?? false));

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
     * @param array<int,array<string,mixed>> $positions
     * @return array<string,mixed>
     */
    public function buildClaimPlanFromPositions(PmCustodyWallet $wallet, array $positions): array
    {
        $primary = $positions[0] ?? [];
        $conditionId = strtolower(trim((string) ($primary['conditionId'] ?? '')));
        $isNegativeRisk = (bool) ($primary['negativeRisk'] ?? false);
        $indexSets = $this->resolveIndexSetsFromPositions($positions);
        $collateralToken = strtolower((string) config('pm.claim_collateral_token', config('pm.collateral_token')));
        $calldata = null;

        if ($conditionId !== '') {
            $calldata = $isNegativeRisk
                ? $this->encodeNegRiskRedeemCalldataFromPositions($positions, $conditionId)
                : $this->encodeRedeemPositionsCalldata(
                    $collateralToken,
                    self::ZERO_BYTES32,
                    $conditionId,
                    $indexSets,
                );
        }

        $claimableValue = array_sum(array_map(static fn (array $position) => (float) ($position['currentValue'] ?? 0), $positions));

        return [
            'ready' => $conditionId !== '' && $calldata !== null,
            'wallet_id' => $wallet->id,
            'member_id' => $wallet->member_id,
            'trading_address' => $wallet->tradingAddress(),
            'signer_address' => strtolower((string) $wallet->signer_address),
            'chain_id' => (int) config('pm.chain_id', 137),
            'rpc_url_configured' => trim((string) config('pm.polygon_rpc_url')) !== '',
            'contract' => strtolower((string) ($isNegativeRisk ? config('pm.neg_risk_adapter_contract') : config('pm.ctf_contract'))),
            'method' => 'redeemPositions',
            'signature' => $isNegativeRisk
                ? 'redeemPositions(bytes32,uint256[])'
                : 'redeemPositions(address,bytes32,bytes32,uint256[])',
            'selector' => $isNegativeRisk ? self::NEG_RISK_REDEEM_POSITIONS_SELECTOR : self::REDEEM_POSITIONS_SELECTOR,
            'params' => $isNegativeRisk
                ? [
                    'conditionId' => $conditionId,
                    'amounts' => $this->resolveNegRiskRedeemAmountsFromPositions($positions),
                ]
                : [
                    'collateralToken' => $collateralToken,
                    'parentCollectionId' => self::ZERO_BYTES32,
                    'conditionId' => $conditionId,
                    'indexSets' => $indexSets,
                ],
            'encoded_args' => $calldata !== null ? substr($calldata, 10) : null,
            'calldata' => $calldata,
            'claimable_value' => $claimableValue,
            'negative_risk' => $isNegativeRisk,
            'positions_count' => count($positions),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $positions
     * @return array<string,mixed>
     */
    public function claimPositionsOnChain(PmCustodyWallet $wallet, array $positions, bool $dryRun = false): array
    {
        $totalCurrentValue = array_sum(array_map(static fn (array $position) => (float) ($position['currentValue'] ?? 0), $positions));
        $hasRedeemableBalance = collect($positions)->contains(function (array $position) {
            return (bool) ($position['redeemable'] ?? false) && ((float) ($position['size'] ?? 0) > 0);
        });

        if ($totalCurrentValue <= 0 && !$hasRedeemableBalance) {
            return [
                'submitted' => false,
                'success' => false,
                'reason' => 'nothing_to_claim',
                'error' => 'nothing_to_claim',
                'tx_hash' => null,
            ];
        }

        $plan = $this->buildClaimPlanFromPositions($wallet, $positions);
        if (($plan['ready'] ?? false) !== true) {
            return $plan + [
                'submitted' => false,
                'success' => false,
                'reason' => 'missing_claim_context',
                'error' => 'missing_claim_context',
                'tx_hash' => null,
            ];
        }

        if ($dryRun) {
            return $plan + [
                'submitted' => false,
                'success' => false,
                'reason' => 'dry_run',
                'tx_hash' => null,
            ];
        }

        $privateKey = $this->privateKeyResolver->resolve($wallet);
        $isNegativeRisk = (bool) ($plan['negative_risk'] ?? false);
        $collateralCandidates = $isNegativeRisk
            ? ['']
            : array_values(array_unique(array_filter([
                (string) config('pm.legacy_collateral_token', ''),
                (string) config('pm.claim_collateral_token', ''),
                (string) config('pm.collateral_token', ''),
            ])));

        foreach ($collateralCandidates as $collateralToken) {
            $result = $this->attemptClaimPositionsOnChain(
                $wallet,
                $positions,
                $privateKey,
                strtolower((string) $collateralToken)
            );

            if (($result['success'] ?? false) === true) {
                return $plan + $result;
            }

            if ($isNegativeRisk || ($result['error'] ?? '') !== 'claim_effect_not_observed') {
                return $plan + $result;
            }
        }

        return $plan + [
            'submitted' => false,
            'success' => false,
            'reason' => 'failed',
            'error' => 'claim_effect_not_observed',
            'tx_hash' => null,
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
                    return $this->receiptStatusSucceeded($receipt);
                }
            } catch (\Exception) {
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
        } catch (\Exception) {
            return null;
        }
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

    private function encodeNegRiskRedeemCalldata(PmOrder $order, string $conditionId): string
    {
        $amounts = $this->resolveNegRiskRedeemAmounts($order);
        $head = '';
        $head .= $this->encodeBytes32($conditionId);
        $head .= $this->encodeUint256(64);

        $tail = $this->encodeUint256(count($amounts));
        foreach ($amounts as $amount) {
            $tail .= $this->encodeDecimalUint256($amount);
        }

        return self::NEG_RISK_REDEEM_POSITIONS_SELECTOR . $head . $tail;
    }

    /**
     * @param array<int,array<string,mixed>> $positions
     */
    private function encodeNegRiskRedeemCalldataFromPositions(array $positions, string $conditionId): string
    {
        $amounts = $this->resolveNegRiskRedeemAmountsFromPositions($positions);
        $head = '';
        $head .= $this->encodeBytes32($conditionId);
        $head .= $this->encodeUint256(64);

        $tail = $this->encodeUint256(count($amounts));
        foreach ($amounts as $amount) {
            $tail .= $this->encodeDecimalUint256($amount);
        }

        return self::NEG_RISK_REDEEM_POSITIONS_SELECTOR . $head . $tail;
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

    /**
     * @param array<int,array<string,mixed>> $positions
     * @return array<int,int>
     */
    private function resolveIndexSetsFromPositions(array $positions): array
    {
        $indexSets = [];
        foreach ($positions as $position) {
            $outcomeIndex = $position['outcomeIndex'] ?? null;
            if ($outcomeIndex !== null && is_numeric($outcomeIndex)) {
                $index = (int) $outcomeIndex;
                if ($index >= 0 && $index <= 255) {
                    $indexSets[] = 1 << $index;
                }
            }
        }

        $indexSets = array_values(array_unique($indexSets));
        return $indexSets !== [] ? $indexSets : [1, 2];
    }

    private function isNegativeRiskOrder(PmOrder $order): bool
    {
        return (bool) (
            Arr::get($order->settlement_payload, 'market.negativeRisk')
            ?? Arr::get($order->settlement_payload, 'market.negative_risk')
            ?? Arr::get($order->settlement_payload, 'negativeRisk')
            ?? Arr::get($order->settlement_payload, 'negative_risk')
            ?? false
        );
    }

    /**
     * @return array<int,string>
     */
    private function resolveNegRiskRedeemAmounts(PmOrder $order): array
    {
        $amount = $this->decimalToTokenUnits((string) ($order->filled_size ?? '0'));
        $outcome = strtolower(trim((string) ($order->outcome ?? $order->winning_outcome ?? '')));

        if ($outcome === 'no' || $outcome === 'down') {
            return ['0', $amount];
        }

        return [$amount, '0'];
    }

    /**
     * @param array<int,array<string,mixed>> $positions
     * @return array<int,string>
     */
    private function resolveNegRiskRedeemAmountsFromPositions(array $positions): array
    {
        $amounts = ['0', '0'];

        foreach ($positions as $position) {
            $size = (string) ($position['size'] ?? '0');
            $scaledAmount = $this->decimalToTokenUnits($size);
            if (bccomp($scaledAmount, '0', 0) <= 0) {
                continue;
            }

            $outcomeIndex = $position['outcomeIndex'] ?? null;
            if (!is_numeric($outcomeIndex)) {
                continue;
            }

            $index = (int) $outcomeIndex;
            if ($index < 0 || $index > 1) {
                continue;
            }

            $amounts[$index] = bcadd($amounts[$index], $scaledAmount, 0);
        }

        return $amounts;
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

    /**
     * @param array<int,array<string,mixed>> $positions
     * @return array<string,mixed>
     */
    private function attemptClaimPositionsOnChain(
        PmCustodyWallet $wallet,
        array $positions,
        string $privateKey,
        string $collateralToken
    ): array {
        $plan = $this->buildClaimPlanFromPositions($wallet, $positions);
        $isNegativeRisk = (bool) ($plan['negative_risk'] ?? false);
        $conditionId = strtolower((string) ($plan['params']['conditionId'] ?? ''));
        $calldata = $isNegativeRisk
            ? (string) ($plan['calldata'] ?? '')
            : $this->encodeRedeemPositionsCalldata(
                $collateralToken,
                self::ZERO_BYTES32,
                $conditionId,
                $this->resolveIndexSetsFromPositions($positions)
            );

        $credential = Credential::fromKey(ltrim($privateKey, '0x'));
        $from = strtolower($credential->getAddress());
        $rpc = app(PolygonRpcService::class);
        $beforeBalances = $this->getGroupTokenBalances($wallet, $positions, $rpc, true);
        $beforeBalance = array_sum(array_filter($beforeBalances, static fn ($value) => $value !== null));
        $beforeCollateralBalance = $this->getCollateralBalance($wallet, $rpc);

        $tx = [
            'from' => $from,
            'to' => strtolower((string) ($isNegativeRisk ? config('pm.neg_risk_adapter_contract') : config('pm.ctf_contract'))),
            'value' => '0x0',
            'data' => $calldata,
        ];

        try {
            $rpc->call('eth_call', [$tx, 'latest']);
        } catch (\Throwable $e) {
            return [
                'submitted' => false,
                'success' => false,
                'reason' => 'failed',
                'error' => 'eth_call 预检查失败: ' . $e->getMessage(),
                'tx_hash' => null,
            ];
        }

        $nonce = $rpc->getTransactionCount($from, 'pending');
        $baseGasPrice = $rpc->rpcQuantityToInt($rpc->gasPrice());
        $safeGasPrice = (int) ($baseGasPrice * 1.2);
        $gasLimit = $this->resolveGasLimitFromRpc($rpc, $tx, $isNegativeRisk);

        $raw = [
            'nonce' => $rpc->toRpcHex($nonce),
            'gasPrice' => $rpc->toRpcHex($safeGasPrice),
            'gasLimit' => $rpc->toRpcHex($gasLimit),
            'to' => $tx['to'],
            'value' => $rpc->toRpcHex(0),
            'data' => $calldata,
            'chainId' => (int) config('pm.chain_id', 137),
        ];

        $signed = $credential->signTransaction($raw);
        $txHash = $rpc->sendRawTransaction($signed);

        for ($i = 0; $i < 30; $i++) {
            sleep(2);
            try {
                $receipt = $rpc->getTransactionReceipt($txHash);
                if ($receipt === null) {
                    continue;
                }

                $rawStatus = $receipt['status'] ?? null;
                $normalizedStatus = $rpc->normalizeReceiptStatus($rawStatus);
                if (!$rpc->receiptStatusSucceeded($receipt)) {
                    return [
                        'submitted' => true,
                        'success' => false,
                        'reason' => 'failed',
                        'error' => '交易失败',
                        'tx_hash' => $txHash,
                        'receipt_status' => $rawStatus,
                        'normalized_receipt_status' => $normalizedStatus,
                    ];
                }

                $afterBalances = $this->getGroupTokenBalances($wallet, $positions, $rpc, false);
                $afterBalance = array_sum(array_filter($afterBalances, static fn ($value) => $value !== null));
                $afterCollateralBalance = $this->getCollateralBalance($wallet, $rpc);
                $verified = $this->verifyClaimSuccess($beforeBalance, $afterBalance, $beforeCollateralBalance, $afterCollateralBalance);

                return [
                    'submitted' => true,
                    'success' => $verified,
                    'reason' => $verified ? 'submitted' : 'failed',
                    'error' => $verified ? null : 'claim_effect_not_observed',
                    'tx_hash' => $txHash,
                    'block_number' => isset($receipt['blockNumber']) ? hexdec($receipt['blockNumber']) : 0,
                    'gas_used' => isset($receipt['gasUsed']) ? hexdec($receipt['gasUsed']) : 0,
                    'receipt_status' => $rawStatus,
                    'normalized_receipt_status' => $normalizedStatus,
                    'before_balance' => $beforeBalance,
                    'after_balance' => $afterBalance,
                    'before_collateral_balance' => $beforeCollateralBalance,
                    'after_collateral_balance' => $afterCollateralBalance,
                    'balance_verified' => $verified,
                ];
            } catch (\Throwable) {
            }
        }

        return [
            'submitted' => true,
            'success' => false,
            'reason' => 'failed',
            'error' => '交易确认超时，请手动查看: https://polygonscan.com/tx/' . $txHash,
            'tx_hash' => $txHash,
        ];
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

    private function encodeDecimalUint256(string $value): string
    {
        return str_pad($this->decToHex($value), 64, '0', STR_PAD_LEFT);
    }

    private function decimalToTokenUnits(string $amount): string
    {
        $amount = trim($amount);
        if ($amount === '' || !preg_match('/^\d+(\.\d+)?$/', $amount)) {
            return '0';
        }

        if (!str_contains($amount, '.')) {
            return bcmul($amount, '1000000', 0);
        }

        [$whole, $fraction] = explode('.', $amount, 2);
        $fraction = substr(str_pad($fraction, 6, '0'), 0, 6);

        return bcadd(bcmul($whole, '1000000', 0), $fraction, 0);
    }

    private function getCollateralBalance(PmCustodyWallet $wallet, ?PolygonRpcService $rpcService = null): ?int
    {
        $collateralToken = trim((string) config('pm.claim_collateral_token', config('pm.collateral_token')));
        if ($collateralToken === '') {
            return null;
        }

        try {
            $rpcService ??= app(PolygonRpcService::class);
            $method = '0x70a08231';
            $addressHex = str_pad(substr(strtolower($wallet->signer_address), 2), 64, '0', STR_PAD_LEFT);
            $result = $rpcService->call('eth_call', [[
                'to' => strtolower($collateralToken),
                'data' => $method . $addressHex,
            ], 'latest']);

            if (!is_string($result)) {
                return null;
            }

            return $rpcService->rpcQuantityToInt($result);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $positions
     * @return array<int,?int>
     */
    private function getGroupTokenBalances(PmCustodyWallet $wallet, array $positions, ?PolygonRpcService $rpcService = null, bool $fallbackToClaimable = false): array
    {
        $balances = [];
        foreach ($positions as $position) {
            $balances[] = $this->getChainTokenBalance($wallet, (string) ($position['asset'] ?? ''), $rpcService, $fallbackToClaimable);
        }
        return $balances;
    }

    private function getChainTokenBalance(PmCustodyWallet $wallet, string $tokenId, ?PolygonRpcService $rpcService = null, bool $fallbackToClaimable = false): ?int
    {
        $tokenId = trim($tokenId);
        if ($tokenId === '' || !preg_match('/^\d+$/', $tokenId)) {
            return 0;
        }

        $ctfContract = (string) config('pm.ctf_contract');
        if ($ctfContract === '') {
            return $fallbackToClaimable ? null : 0;
        }

        try {
            $rpcService ??= app(PolygonRpcService::class);
            $method = '0x00fdd58e';
            $addressHex = str_pad(substr(strtolower($wallet->signer_address), 2), 64, '0', STR_PAD_LEFT);
            $tokenHex = str_pad($this->decToHex($tokenId), 64, '0', STR_PAD_LEFT);
            $data = $method . $addressHex . $tokenHex;
            $result = $rpcService->call('eth_call', [[
                'to' => strtolower($ctfContract),
                'data' => $data,
            ], 'latest']);

            if (!is_string($result)) {
                return 0;
            }

            return $rpcService->rpcQuantityToInt($result);
        } catch (\Throwable) {
            return $fallbackToClaimable ? null : 0;
        }
    }

    private function verifyClaimSuccess(?int $beforeTokenBalance, ?int $afterTokenBalance, ?int $beforeCollateralBalance, ?int $afterCollateralBalance): bool
    {
        $tokenReduced = $beforeTokenBalance !== null && $afterTokenBalance !== null && $afterTokenBalance < $beforeTokenBalance;
        $collateralIncreased = $beforeCollateralBalance !== null && $afterCollateralBalance !== null && $afterCollateralBalance > $beforeCollateralBalance;

        return $tokenReduced || $collateralIncreased;
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

    private function resolveGasLimit(string $rpcUrl, array $tx, bool $isNegativeRisk): int
    {
        $fallback = $isNegativeRisk ? 400000 : 220000;

        try {
            $estimated = $this->rpc($rpcUrl, 'eth_estimateGas', [$tx]);
            $estimatedInt = $this->rpcQuantityToInt($estimated);
            if ($estimatedInt <= 0) {
                return $fallback;
            }

            return max((int) ceil($estimatedInt * 1.3), $fallback);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function resolveGasLimitFromRpc(PolygonRpcService $rpc, array $tx, bool $isNegativeRisk): int
    {
        $fallback = $isNegativeRisk ? 400000 : 220000;

        try {
            $estimated = $rpc->call('eth_estimateGas', [$tx]);
            $estimatedInt = $rpc->rpcQuantityToInt($estimated);
            if ($estimatedInt <= 0) {
                return $fallback;
            }

            return max((int) ceil($estimatedInt * 1.3), $fallback);
        } catch (\Throwable) {
            return $fallback;
        }
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

    private function toRpcHex(int $value): string
    {
        return '0x' . dechex($value);
    }
}
