<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyWallet;
use App\Models\Pm\PmOrder;
use App\Models\Pm\PmPolymarketApiCredential;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use EthTool\Credential;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use PolymarketPhp\Polymarket\Auth\ApiCredentials;
use PolymarketPhp\Polymarket\Auth\ClobAuthenticator;
use PolymarketPhp\Polymarket\Auth\Signer\Eip712Signer as ClobAuthEip712Signer;
use PolymarketPhp\Polymarket\Enums\SignatureType;

class PolymarketTradingService
{
    public const SIDE_BUY = 'BUY';
    public const SIDE_SELL = 'SELL';
    public const ASSET_COLLATERAL = 'COLLATERAL';
    public const ASSET_CONDITIONAL = 'CONDITIONAL';

    public function __construct(
        private readonly CustodyCipher $cipher,
        private readonly PmPrivateKeyResolver $privateKeyResolver,
        private readonly PolymarketOrderSigner $orderSigner,
        private readonly PolymarketClientFactory $factory,
    ) {}

    public function ensureApiCredentials(PmCustodyWallet $wallet): PmPolymarketApiCredential
    {
        $privateKey = $this->privateKeyResolver->resolve($wallet);
        $signer = new ClobAuthEip712Signer($privateKey, (int) config('pm.chain_id', 137));
        $auth = new ClobAuthenticator(
            $signer,
            (string) config('pm.clob_base_url'),
            (int) config('pm.chain_id', 137)
        );

        $creds = $auth->deriveOrCreateCredentials(0);

        return PmPolymarketApiCredential::updateOrCreate(
            ['custody_wallet_id' => $wallet->id],
            [
                'api_key_ciphertext' => $this->cipher->encryptString($creds->apiKey),
                'api_secret_ciphertext' => $this->cipher->encryptString($creds->apiSecret),
                'passphrase_ciphertext' => $this->cipher->encryptString($creds->passphrase),
                'encryption_version' => 1,
                'derived_at' => now(),
                'last_validated_at' => now(),
            ]
        );
    }

    public function decodeApiCredentials(PmPolymarketApiCredential $record): ApiCredentials
    {
        return new ApiCredentials(
            $this->cipher->decryptString($record->api_key_ciphertext),
            $this->cipher->decryptString($record->api_secret_ciphertext),
            $this->cipher->decryptString($record->passphrase_ciphertext),
        );
    }

    public function reserveExchangeNonce(PmCustodyWallet $wallet): string
    {
        $currentStored = (string) ($wallet->exchange_nonce ?: '0');
        $timeBased = (string) ((int) floor(microtime(true) * 1000));
        $next = bccomp($timeBased, $currentStored, 0) === 1
            ? $timeBased
            : bcadd($currentStored, '1', 0);

        $wallet->exchange_nonce = $next;
        $wallet->save();

        return $next;
    }

    public function syncExchangeNonceFromOrders(PmCustodyWallet $wallet): string
    {
        $max = PmOrder::query()
            ->whereHas('intent', fn ($query) => $query->where('member_id', $wallet->member_id))
            ->whereNotNull('exchange_nonce')
            ->max('exchange_nonce');

        $next = $max !== null && preg_match('/^\d+$/', (string) $max) === 1
            ? bcadd((string) $max, '1', 0)
            : (string) ($wallet->exchange_nonce ?: '0');

        $wallet->exchange_nonce = $next;
        $wallet->save();

        return $next;
    }

    public function exceedsDailyMaxUsdc(int $memberId, ?int $dailyMaxUsdc): bool
    {
        if ($dailyMaxUsdc === null || $dailyMaxUsdc <= 0) {
            return false;
        }

        $start = now()->startOfDay();
        $today = (int) PmOrder::query()
            ->whereHas('intent', fn ($query) => $query->where('member_id', $memberId))
            ->whereIn('status', [PmOrder::STATUS_SUBMITTED, PmOrder::STATUS_FILLED, PmOrder::STATUS_PARTIAL])
            ->where('created_at', '>=', $start)
            ->sum('filled_usdc');

        return $today >= $dailyMaxUsdc;
    }

    /**
     * @return array<string,mixed>
     */
    public function evaluateSlippage(string $tokenId, string $side, string $leaderPrice, int $maxSlippageBps): array
    {
        if ($maxSlippageBps <= 0) {
            return ['passed' => true, 'book_price' => null, 'slippage_bps' => 0];
        }

        $book = $this->factory->makeReadClient()->clob()->book()->get($tokenId);
        $isMarketable = $this->isMarketableOrder($side, $leaderPrice, is_array($book) ? $book : []);
        $levels = match (strtoupper($side)) {
            self::SIDE_BUY => $isMarketable ? ($book['asks'] ?? []) : ($book['bids'] ?? []),
            self::SIDE_SELL => $isMarketable ? ($book['bids'] ?? []) : ($book['asks'] ?? []),
            default => [],
        };
        $best = is_array($levels) && isset($levels[0]['price']) ? (string) $levels[0]['price'] : null;
        if ($best === null || !preg_match('/^\d+(\.\d+)?$/', $best)) {
            return ['passed' => true, 'book_price' => null, 'slippage_bps' => 0];
        }

        $leader = BigDecimal::of($leaderPrice);
        if ($leader->isLessThanOrEqualTo(BigDecimal::zero())) {
            return ['passed' => true, 'book_price' => $best, 'slippage_bps' => 0];
        }

        $bookPrice = BigDecimal::of($best);
        $diff = $bookPrice->minus($leader)->abs();
        $bps = (int) $diff
            ->dividedBy($leader, 8, RoundingMode::HALF_UP)
            ->multipliedBy('10000')
            ->toScale(0, RoundingMode::HALF_UP)
            ->__toString();

        return [
            'passed' => $bps <= $maxSlippageBps,
            'book_price' => $best,
            'slippage_bps' => $bps,
            'is_marketable' => $isMarketable,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getOrderBookBestPrice(string $tokenId, string $side): array
    {
        $book = $this->factory->makeReadClient()->clob()->book()->get($tokenId);
        $levels = match (strtoupper($side)) {
            self::SIDE_BUY => $book['asks'] ?? [],
            self::SIDE_SELL => $book['bids'] ?? [],
            default => [],
        };
        $best = is_array($levels) && isset($levels[0]['price']) ? (string) $levels[0]['price'] : null;

        return [
            'book' => is_array($book) ? $book : [],
            'price' => $best,
        ];
    }

    /**
     * 获取用户订单列表。
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function getUserOrders(PmCustodyWallet $wallet, array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $credRecord = $wallet->apiCredentials ?: $this->ensureApiCredentials($wallet);
        $creds = $this->decodeApiCredentials($credRecord);
        $privateKey = $this->privateKeyResolver->resolve($wallet);
        $client = $this->factory->makeAuthedClobClient($privateKey, $creds);

        return $this->withTransientClobRetry(
            fn () => $client->clob()->orders()->list($filters, $limit, $offset),
            '读取 Polymarket 用户订单失败'
        );
    }

    /**
     * 获取单个用户订单。
     *
     * @return array<string,mixed>
     */
    public function getUserOrder(PmCustodyWallet $wallet, string $polyOrderId): array
    {
        $credRecord = $wallet->apiCredentials ?: $this->ensureApiCredentials($wallet);
        $creds = $this->decodeApiCredentials($credRecord);
        $privateKey = $this->privateKeyResolver->resolve($wallet);
        $client = $this->factory->makeAuthedClobClient($privateKey, $creds);

        return $this->withTransientClobRetry(
            fn () => $client->clob()->orders()->get($polyOrderId),
            '读取 Polymarket 单个订单失败'
        );
    }

    public function getBalanceAllowance(PmCustodyWallet $wallet): array
    {
        return $this->getAssetAllowanceStatus($wallet, self::ASSET_COLLATERAL);
    }

    /**
     * @return array<string,mixed>
     */
    public function getAllowanceStatus(PmCustodyWallet $wallet): array
    {
        return $this->summarizeAllowanceStatus($this->getBalanceAllowance($wallet));
    }

    /**
     * @return array<string,mixed>
     */
    public function getConditionalAllowanceStatus(PmCustodyWallet $wallet, string $tokenId): array
    {
        return $this->summarizeAllowanceStatus(
            $this->getAssetAllowanceStatus($wallet, self::ASSET_CONDITIONAL, $tokenId)
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function getTradingReadiness(PmCustodyWallet $wallet, string $side, ?string $tokenId = null, ?string $price = null, ?string $size = null): array
    {
        $side = strtoupper(trim($side));
        if (!in_array($side, [self::SIDE_BUY, self::SIDE_SELL], true)) {
            return [
                'side' => $side,
                'is_ready' => false,
                'failure_code' => 'invalid_side',
                'checks' => [],
            ];
        }

        if ($side === self::SIDE_BUY) {
            $status = $this->getAllowanceStatus($wallet);
            $balance = (string) ($status['balance'] ?? '0');
            $checks = [
                [
                    'code' => 'collateral_balance',
                    'passed' => $this->isPositiveDecimal($balance),
                    'value' => $balance,
                ],
                [
                    'code' => 'collateral_allowance',
                    'passed' => (bool) ($status['is_approved'] ?? false),
                    'value' => $status['allowances'] ?? [],
                ],
            ];

            $requiredNotional = null;
            if ($price !== null && $size !== null && $this->isPositiveDecimal($price) && $this->isPositiveDecimal($size)) {
                $requiredNotional = BigDecimal::of($price)
                    ->multipliedBy($size)
                    ->toScale(6, RoundingMode::DOWN)
                    ->stripTrailingZeros()
                    ->__toString();
                $checks[] = [
                    'code' => 'collateral_required_notional',
                    'passed' => bccomp($balance, $requiredNotional, 6) >= 0,
                    'value' => $requiredNotional,
                ];
            }

            foreach ($checks as $check) {
                if ($check['passed'] !== true) {
                    return [
                        'side' => $side,
                        'is_ready' => false,
                        'failure_code' => $check['code'] === 'collateral_required_notional'
                            ? 'insufficient_collateral_balance'
                            : ($check['code'] === 'collateral_allowance'
                                ? 'insufficient_collateral_allowance'
                                : 'insufficient_collateral_balance'),
                        'checks' => $checks,
                        'status' => $status,
                    ];
                }
            }

            return [
                'side' => $side,
                'is_ready' => true,
                'failure_code' => null,
                'checks' => $checks,
                'status' => $status,
            ];
        }

        if ($tokenId === null || trim($tokenId) === '') {
            return [
                'side' => $side,
                'is_ready' => false,
                'failure_code' => 'missing_token_id',
                'checks' => [],
            ];
        }

        $status = $this->getConditionalAllowanceStatus($wallet, $tokenId);
        $balanceUnits = (string) ($status['balance'] ?? '0');
        $hasBalance = $this->isPositiveInteger($balanceUnits);
        $hasApproval = (bool) ($status['is_approved'] ?? false);
        $checks = [
            [
                'code' => 'position_balance',
                'passed' => $hasBalance,
                'value' => $balanceUnits,
            ],
            [
                'code' => 'erc1155_approval',
                'passed' => $hasApproval,
                'value' => $status['allowances'] ?? [],
            ],
        ];

        if ($size !== null && trim($size) !== '' && $this->isPositiveDecimal($size)) {
            $requiredUnits = BigDecimal::of($size)
                ->multipliedBy('1000000')
                ->toScale(0, RoundingMode::DOWN)
                ->__toString();
            $checks[] = [
                'code' => 'position_required_size',
                'passed' => bccomp($balanceUnits, $requiredUnits, 0) >= 0,
                'value' => $requiredUnits,
            ];
        }

        foreach ($checks as $check) {
            if ($check['passed'] !== true) {
                return [
                    'side' => $side,
                    'is_ready' => false,
                    'failure_code' => match ($check['code']) {
                        'erc1155_approval' => 'missing_erc1155_approval',
                        'position_required_size', 'position_balance' => 'insufficient_position_balance',
                        default => 'sell_not_ready',
                    },
                    'checks' => $checks,
                    'status' => $status,
                ];
            }
        }

        return [
            'side' => $side,
            'is_ready' => true,
            'failure_code' => null,
            'checks' => $checks,
            'status' => $status,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function approveCollateral(PmCustodyWallet $wallet): array
    {
        $status = $this->getAllowanceStatus($wallet);
        if (($status['is_approved'] ?? false) === true) {
            return [
                'submitted' => false,
                'already_approved' => true,
                'tx_hashes' => [],
                'allowance' => $status,
                'side' => self::SIDE_BUY,
            ];
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
        $txHashes = [];
        $token = (string) config('pm.collateral_token');
        $chainId = (int) config('pm.chain_id', 137);
        $gasLimit = 70000;
        $amount = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

        foreach ($this->approvalSpenders() as $spender) {
            $data = '0x095ea7b3'
                . $this->padHex(substr(strtolower($spender), 2))
                . $amount;

            $raw = [
                'nonce' => $this->toRpcHex($nonce),
                'gasPrice' => $gasPriceHex,
                'gasLimit' => $this->toRpcHex($gasLimit),
                'to' => $token,
                'value' => $this->toRpcHex(0),
                'data' => $data,
                'chainId' => $chainId,
            ];

            $signed = $credential->signTransaction($raw);
            $txHashes[] = [
                'spender' => $spender,
                'tx_hash' => (string) $this->rpc($rpcUrl, 'eth_sendRawTransaction', [$signed]),
            ];
            $nonce++;
        }

        return [
            'submitted' => true,
            'already_approved' => false,
            'tx_hashes' => $txHashes,
            'amount' => 'MAX',
            'chain_id' => $chainId,
            'token' => $token,
            'side' => self::SIDE_BUY,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function approveSellToken(PmCustodyWallet $wallet, string $tokenId): array
    {
        $status = $this->getConditionalAllowanceStatus($wallet, $tokenId);
        if (($status['is_approved'] ?? false) === true) {
            return [
                'submitted' => false,
                'already_approved' => true,
                'tx_hashes' => [],
                'allowance' => $status,
                'token_id' => $tokenId,
                'side' => self::SIDE_SELL,
            ];
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
        $ctfContract = strtolower((string) config('pm.ctf_contract'));
        $chainId = (int) config('pm.chain_id', 137);
        $gasLimit = 120000;
        $txHashes = [];
        $enabled = str_pad('1', 64, '0', STR_PAD_LEFT);

        foreach ($this->approvalSpenders() as $spender) {
            $data = '0xa22cb465'
                . $this->padHex(substr(strtolower($spender), 2))
                . $enabled;

            $raw = [
                'nonce' => $this->toRpcHex($nonce),
                'gasPrice' => $gasPriceHex,
                'gasLimit' => $this->toRpcHex($gasLimit),
                'to' => $ctfContract,
                'value' => $this->toRpcHex(0),
                'data' => $data,
                'chainId' => $chainId,
            ];

            $signed = $credential->signTransaction($raw);
            $txHashes[] = [
                'spender' => $spender,
                'tx_hash' => (string) $this->rpc($rpcUrl, 'eth_sendRawTransaction', [$signed]),
            ];
            $nonce++;
        }

        return [
            'submitted' => true,
            'already_approved' => false,
            'tx_hashes' => $txHashes,
            'token_id' => $tokenId,
            'ctf_contract' => $ctfContract,
            'side' => self::SIDE_SELL,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function approveForSide(PmCustodyWallet $wallet, string $side, ?string $tokenId = null): array
    {
        $side = strtoupper(trim($side));

        return match ($side) {
            self::SIDE_BUY => $this->approveCollateral($wallet),
            self::SIDE_SELL => $this->approveSellToken(
                $wallet,
                (string) ($tokenId ?: throw new \InvalidArgumentException('SELL 授权需要 token_id'))
            ),
            default => throw new \InvalidArgumentException('不支持的 side'),
        };
    }

    /**
     * @param array{token_id:string,side:string,price:string,size:string,order_type?:string,defer_exec?:bool,expiration?:string|int|null,nonce?:string|int|null,fee_rate_bps?:string|int|null,salt?:string|int|null,outcome?:string|null,market_id?:string|null} $params
     * @return array{request: array<string,mixed>, response: array<string,mixed>}
     */
    public function placeOrder(PmCustodyWallet $wallet, array $params): array
    {
        $credRecord = $wallet->apiCredentials ?: $this->ensureApiCredentials($wallet);
        $creds = $this->decodeApiCredentials($credRecord);
        $privateKey = $this->privateKeyResolver->resolve($wallet);

        $client = $this->factory->makeAuthedClobClient($privateKey, $creds);

        $tokenId = (string) $params['token_id'];
        $side = strtoupper((string) $params['side']);
        $price = (string) $params['price'];
        $size = (string) $params['size'];
        $orderType = strtoupper((string) ($params['order_type'] ?? 'GTC'));
        $deferExec = (bool) ($params['defer_exec'] ?? false);
        $expiration = (string) ($params['expiration'] ?? '0');
        $nonce = (string) ($params['nonce'] ?? ($wallet->exchange_nonce ?: '0'));

        $feeResp = $client->clob()->server()->getFeeRate($tokenId);
        $book = $this->factory->makeReadClient()->clob()->book()->get($tokenId);
        $defaultFeeRateBps = (string) config('pm.default_fee_rate_bps', '1000');
        $makerFeeRateBps = (string) config('pm.maker_fee_rate_bps', '0');
        $takerFeeRateBps = (string) config('pm.taker_fee_rate_bps', '1000');
        $feeRateBps = (string) ($params['fee_rate_bps']
            ?? $feeResp['feeRateBps']
            ?? $feeResp['fee_rate_bps']
            ?? null);
        if (!preg_match('/^\d+$/', $feeRateBps) || $feeRateBps === '0') {
            $feeRateBps = $this->isMarketableOrder($side, $price, is_array($book) ? $book : [])
                ? $takerFeeRateBps
                : $makerFeeRateBps;
        }
        if (!preg_match('/^\d+$/', $feeRateBps)) {
            $feeRateBps = $defaultFeeRateBps;
        }

        $salt = (string) ($params['salt'] ?? $this->makeSalt());
        $signatureType = (int) ($wallet->signature_type ?? SignatureType::EOA->value);
        $funder = strtolower($wallet->funder_address ?: $wallet->signer_address);
        $signer = strtolower($wallet->signer_address);

        $amounts = $this->orderSigner->makeAmounts($side, $price, $size);
        $orderSide = $this->orderSigner->sideToInt($side);

        $order = [
            'salt' => $salt,
            'maker' => $funder,
            'signer' => $signer,
            'taker' => '0x0000000000000000000000000000000000000000',
            'tokenId' => $tokenId,
            'makerAmount' => $amounts['makerAmount'],
            'takerAmount' => $amounts['takerAmount'],
            'expiration' => $expiration,
            'nonce' => $nonce,
            'feeRateBps' => $feeRateBps,
            'side' => $orderSide,
            'signatureType' => $signatureType,
        ];

        $order['signature'] = $this->orderSigner->sign($order, $privateKey);

        $requestOrder = [
            'salt' => (int) $salt,
            'maker' => $funder,
            'signer' => $signer,
            'taker' => '0x0000000000000000000000000000000000000000',
            'tokenId' => (string) $tokenId,
            'makerAmount' => (string) $amounts['makerAmount'],
            'takerAmount' => (string) $amounts['takerAmount'],
            'expiration' => (string) $expiration,
            'nonce' => (string) $nonce,
            'feeRateBps' => (string) $feeRateBps,
            'side' => $side,
            'signatureType' => $signatureType,
            'signature' => $order['signature'],
        ];

        $payload = [
            'order' => $requestOrder,
            'owner' => $creds->apiKey,
            'orderType' => $orderType,
            'postOnly' => $deferExec,
        ];

        $response = $client->clob()->orders()->post($payload);

        return [
            'request' => [
                'endpoint' => '/order',
                'payload' => $payload,
                'input' => [
                    'token_id' => $tokenId,
                    'market_id' => (string) ($params['market_id'] ?? ''),
                    'outcome' => (string) ($params['outcome'] ?? ''),
                    'side' => $side,
                    'price' => $price,
                    'size' => $size,
                    'nonce' => $nonce,
                ],
            ],
            'response' => $response,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getAssetAllowanceStatus(PmCustodyWallet $wallet, string $assetType, ?string $tokenId = null): array
    {
        $credRecord = $wallet->apiCredentials ?: $this->ensureApiCredentials($wallet);
        $creds = $this->decodeApiCredentials($credRecord);
        $privateKey = $this->privateKeyResolver->resolve($wallet);
        $client = $this->factory->makeAuthedClobClient($privateKey, $creds);

        $params = [
            'asset_type' => strtoupper($assetType),
            'signature_type' => $wallet->signature_type,
            'funder' => $wallet->tradingAddress(),
        ];
        if ($tokenId !== null && trim($tokenId) !== '') {
            $params['token_id'] = trim($tokenId);
        }

        return $this->withTransientClobRetry(
            fn () => $client->clob()->account()->getBalanceAllowance($params),
            '读取 Polymarket allowance 失败'
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function summarizeAllowanceStatus(array $raw): array
    {
        $rawAllowances = is_array($raw['allowances'] ?? null) ? $raw['allowances'] : [];
        $allowances = [];
        foreach ($rawAllowances as $key => $value) {
            if (is_string($key)) {
                $allowances[strtolower($key)] = (string) $value;
            }
        }

        $items = [];
        $allApproved = true;
        foreach ($this->approvalSpenders() as $spender) {
            $value = (string) ($allowances[strtolower($spender)] ?? '0');
            $approved = $this->isPositiveDecimal($value) || $this->isPositiveInteger($value);
            $items[] = [
                'spender' => $spender,
                'allowance' => $value,
                'is_approved' => $approved,
            ];
            $allApproved = $allApproved && $approved;
        }

        return [
            'asset_type' => (string) ($raw['asset_type'] ?? ''),
            'token_id' => (string) ($raw['token_id'] ?? ''),
            'balance' => (string) ($raw['balance'] ?? '0'),
            'allowances' => $items,
            'is_approved' => $items !== [] ? $allApproved : false,
            'raw' => $raw,
        ];
    }

    public function isTokenTradable(string $tokenId): bool
    {
        try {
            $book = $this->factory->makeReadClient()->clob()->book()->get($tokenId);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'No orderbook exists for the requested token id')) {
                return false;
            }

            throw $e;
        }

        return is_array($book) && $book !== [];
    }

    private function makeSalt(): string
    {
        return (string) random_int(1, PHP_INT_MAX);
    }

    /**
     * @return array<int,string>
     */
    private function approvalSpenders(): array
    {
        $spenders = config('pm.approval_spenders', []);
        if (!is_array($spenders) || $spenders === []) {
            return [strtolower((string) config('pm.exchange_contract'))];
        }

        return array_values(array_unique(array_map(
            static fn (string $value) => strtolower(trim($value)),
            array_filter($spenders, static fn ($value) => is_string($value) && trim($value) !== '')
        )));
    }

    private function withTransientClobRetry(callable $callback, string $message): array
    {
        $attempts = 0;
        beginning:
        try {
            /** @var array<string,mixed> $result */
            $result = $callback();
            return $result;
        } catch (\Throwable $e) {
            $attempts++;
            if ($attempts < 2 && $this->isTransientClobTlsError($e)) {
                goto beginning;
            }

            throw new \RuntimeException($message . ': ' . $e->getMessage(), previous: $e);
        }
    }

    private function isTransientClobTlsError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 35')
            || str_contains($message, 'curl error 28')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'unexpected eof while reading')
            || str_contains($message, 'ssl routines');
    }

    private function isPositiveDecimal(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^\d+(\.\d+)?$/', $value)) {
            return false;
        }

        return bccomp($value, '0', 18) === 1;
    }

    private function isPositiveInteger(string $value): bool
    {
        $value = trim($value);
        return $value !== '' && preg_match('/^\d+$/', $value) === 1 && bccomp($value, '0', 0) === 1;
    }

    /**
     * @param array<string,mixed> $book
     */
    private function isMarketableOrder(string $side, string $price, array $book): bool
    {
        if (!preg_match('/^\d+(\.\d+)?$/', $price)) {
            return false;
        }

        $levels = strtoupper($side) === self::SIDE_BUY ? ($book['asks'] ?? []) : ($book['bids'] ?? []);
        if (!is_array($levels) || !isset($levels[0]['price']) || !is_string($levels[0]['price'])) {
            return false;
        }

        $best = $levels[0]['price'];
        if (!preg_match('/^\d+(\.\d+)?$/', $best)) {
            return false;
        }

        return strtoupper($side) === self::SIDE_BUY
            ? bccomp($price, $best, 8) >= 0
            : bccomp($price, $best, 8) <= 0;
    }

    private function padHex(string $value): string
    {
        return str_pad(Str::lower(ltrim($value, '0x')), 64, '0', STR_PAD_LEFT);
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
