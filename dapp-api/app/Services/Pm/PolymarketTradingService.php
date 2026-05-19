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
use App\Services\Pm\PolygonRpcService;

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
        private readonly PolygonRpcService $polygonRpc,
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
    public function evaluateSlippage(string $tokenId, string $side, string $leaderPrice, int $maxSlippageBps, ?string $amount = null, ?string $bookPrice = null): array
    {
        if ($maxSlippageBps <= 0) {
            return ['passed' => true, 'book_price' => null, 'slippage_bps' => 0];
        }

        if ($bookPrice !== null && $this->isPositiveDecimal($bookPrice)) {
            $leader = BigDecimal::of($leaderPrice);
            if ($leader->isLessThanOrEqualTo(BigDecimal::zero())) {
                return ['passed' => true, 'book_price' => $bookPrice, 'slippage_bps' => 0, 'is_marketable' => true];
            }

            $price = BigDecimal::of($bookPrice);
            $bps = $this->calculateAdverseSlippageBps($side, $leader, $price);

            return [
                'passed' => $bps <= $maxSlippageBps,
                'book_price' => $bookPrice,
                'slippage_bps' => $bps,
                'is_marketable' => true,
            ];
        }

        if ($amount !== null && $this->isPositiveDecimal($amount)) {
            $marketPrice = $this->getOrderBookMarketPrice($tokenId, $side, $amount);
            $best = isset($marketPrice['price']) ? (string) $marketPrice['price'] : null;
            if ($best === null || !preg_match('/^\d+(\.\d+)?$/', $best)) {
                return ['passed' => true, 'book_price' => null, 'slippage_bps' => 0];
            }

            $leader = BigDecimal::of($leaderPrice);
            if ($leader->isLessThanOrEqualTo(BigDecimal::zero())) {
                return ['passed' => true, 'book_price' => $best, 'slippage_bps' => 0, 'is_marketable' => true];
            }

            $bookPrice = BigDecimal::of($best);
            $bps = $this->calculateAdverseSlippageBps($side, $leader, $bookPrice);

            return [
                'passed' => $bps <= $maxSlippageBps,
                'book_price' => $best,
                'slippage_bps' => $bps,
                'is_marketable' => true,
            ];
        }

        $book = $this->withTransientClobRetry(
            fn () => $this->factory->makeReadClient()->clob()->book()->get($tokenId),
            '读取 Polymarket orderbook 失败'
        );
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
        $bps = $this->calculateAdverseSlippageBps($side, $leader, $bookPrice);

        return [
            'passed' => $bps <= $maxSlippageBps,
            'book_price' => $best,
            'slippage_bps' => $bps,
            'is_marketable' => $isMarketable,
        ];
    }

    private function calculateAdverseSlippageBps(string $side, BigDecimal $leaderPrice, BigDecimal $bookPrice): int
    {
        $adverseDiff = match (strtoupper($side)) {
            self::SIDE_BUY => $bookPrice->isGreaterThan($leaderPrice)
                ? $bookPrice->minus($leaderPrice)
                : BigDecimal::zero(),
            self::SIDE_SELL => $bookPrice->isLessThan($leaderPrice)
                ? $leaderPrice->minus($bookPrice)
                : BigDecimal::zero(),
            default => $bookPrice->minus($leaderPrice)->abs(),
        };

        return (int) $adverseDiff
            ->dividedBy($leaderPrice, 8, RoundingMode::HALF_UP)
            ->multipliedBy('10000')
            ->toScale(0, RoundingMode::HALF_UP)
            ->__toString();
    }

    /**
     * @return array<string,mixed>
     */
    public function getOrderBook(string $tokenId): array
    {
        return $this->withTransientClobRetry(
            fn () => $this->factory->makeReadClient()->clob()->book()->get($tokenId),
            '读取 Polymarket orderbook 失败'
        );
    }

    /**
     * @return array{book: array<string,mixed>, price: ?string, min_size: ?string}
     */
    public function getOrderBookBestPrice(string $tokenId, string $side): array
    {
        $book = $this->withTransientClobRetry(
            fn () => $this->factory->makeReadClient()->clob()->book()->get($tokenId),
            '读取 Polymarket orderbook 失败'
        );
        $levels = match (strtoupper($side)) {
            self::SIDE_BUY => $book['asks'] ?? [],
            self::SIDE_SELL => $book['bids'] ?? [],
            default => [],
        };
        $best = is_array($levels) && isset($levels[0]['price']) ? (string) $levels[0]['price'] : null;

        return [
            'book' => is_array($book) ? $book : [],
            'price' => $best,
            'min_size' => isset($book['min_order_size']) ? (string) $book['min_order_size'] : null,
        ];
    }

    /**
     * 按订单簿深度估算可立即成交的市价参考价。
     * BUY 按 USDC 金额吃 asks；SELL 按 token 数量吃 bids。
     *
     * @param array<string,mixed>|null $book 可选的已缓存 orderbook，避免重复请求
     * @return array<string,mixed>
     */
    public function getOrderBookMarketPrice(string $tokenId, string $side, string $amount, ?string $size = null, ?array $book = null): array
    {
        if ($book === null) {
            $book = $this->withTransientClobRetry(
                fn () => $this->factory->makeReadClient()->clob()->book()->get($tokenId),
                '读取 Polymarket orderbook 失败'
            );
        }
        $levels = match (strtoupper($side)) {
            self::SIDE_BUY => $book['asks'] ?? [],
            self::SIDE_SELL => $book['bids'] ?? [],
            default => [],
        };

        if (!is_array($levels) || $levels === []) {
            return [
                'book' => is_array($book) ? $book : [],
                'price' => null,
                'depth_reached' => false,
                'consumable_size' => '0',
                'consumable_notional' => '0',
                'min_size' => isset($book['min_order_size']) ? (string) $book['min_order_size'] : null,
            ];
        }

        usort($levels, static function (array $a, array $b) use ($side): int {
            $priceA = is_string($a['price'] ?? null) ? (float) $a['price'] : 0.0;
            $priceB = is_string($b['price'] ?? null) ? (float) $b['price'] : 0.0;

            return strtoupper($side) === self::SIDE_BUY
                ? $priceA <=> $priceB
                : $priceB <=> $priceA;
        });

        $remaining = strtoupper($side) === self::SIDE_BUY && $size !== null && preg_match('/^\d+(\.\d+)?$/', $size) === 1 && bccomp($size, '0', 8) === 1
            ? BigDecimal::of($size)
            : BigDecimal::of($amount);
        $useSizeForBuy = strtoupper($side) === self::SIDE_BUY && $size !== null && preg_match('/^\d+(\.\d+)?$/', $size) === 1 && bccomp($size, '0', 8) === 1;
        $marketPrice = null;
        $consumedSize = BigDecimal::zero();
        $consumedNotional = BigDecimal::zero();

        foreach ($levels as $level) {
            $price = isset($level['price']) ? trim((string) $level['price']) : '';
            $levelSize = isset($level['size']) ? trim((string) $level['size']) : '';
            if (!$this->isPositiveDecimal($price) || !$this->isPositiveDecimal($levelSize)) {
                continue;
            }

            $priceDec = BigDecimal::of($price);
            $sizeDec = BigDecimal::of($levelSize);
            $consumable = strtoupper($side) === self::SIDE_BUY
                ? ($useSizeForBuy ? $sizeDec : $priceDec->multipliedBy($sizeDec))
                : $sizeDec;

            $marketPrice = $price;
            if ($remaining->isLessThanOrEqualTo($consumable)) {
                $fillSize = strtoupper($side) === self::SIDE_BUY
                    ? ($useSizeForBuy ? $remaining : $remaining->dividedBy($priceDec, 8, RoundingMode::DOWN))
                    : $remaining;

                $consumedSize = $consumedSize->plus($fillSize);
                $consumedNotional = $consumedNotional->plus($priceDec->multipliedBy($fillSize));

                return [
                    'book' => is_array($book) ? $book : [],
                    'price' => $marketPrice,
                    'depth_reached' => true,
                    'consumable_size' => $consumedSize->toScale(8, RoundingMode::DOWN)->stripTrailingZeros()->__toString(),
                    'consumable_notional' => $consumedNotional->toScale(6, RoundingMode::DOWN)->stripTrailingZeros()->__toString(),
                    'min_size' => isset($book['min_order_size']) ? (string) $book['min_order_size'] : null,
                ];
            }

            $consumedSize = $consumedSize->plus($sizeDec);
            $consumedNotional = $consumedNotional->plus($priceDec->multipliedBy($sizeDec));
            $remaining = $remaining->minus($consumable);
        }

        return [
            'book' => is_array($book) ? $book : [],
            'price' => $marketPrice,
            'depth_reached' => false,
            'consumable_size' => $consumedSize->toScale(8, RoundingMode::DOWN)->stripTrailingZeros()->__toString(),
            'consumable_notional' => $consumedNotional->toScale(6, RoundingMode::DOWN)->stripTrailingZeros()->__toString(),
            'min_size' => isset($book['min_order_size']) ? (string) $book['min_order_size'] : null,
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

    public function getBalanceAllowance(PmCustodyWallet $wallet, bool $refresh = false): array
    {
        return $this->getAssetAllowanceStatus($wallet, self::ASSET_COLLATERAL, null, $refresh);
    }

    /**
     * @return array<string,mixed>
     */
    public function getAllowanceStatus(PmCustodyWallet $wallet, bool $refresh = false): array
    {
        return $this->summarizeAllowanceStatus($this->getBalanceAllowance($wallet, $refresh));
    }

    /**
     * @return array<string,mixed>
     */
    public function getConditionalAllowanceStatus(PmCustodyWallet $wallet, string $tokenId, bool $refresh = false): array
    {
        return $this->summarizeAllowanceStatus(
            $this->getAssetAllowanceStatus($wallet, self::ASSET_CONDITIONAL, $tokenId, $refresh)
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
            $this->ensureWrappedCollateral($wallet);
            $credRecord = $wallet->apiCredentials ?: $this->ensureApiCredentials($wallet);
            $creds = $this->decodeApiCredentials($credRecord);
            $privateKey = $this->privateKeyResolver->resolve($wallet);
            $client = $this->factory->makeAuthedClobClient($privateKey, $creds);

            $params = [
                'asset_type' => 'COLLATERAL',
                'signature_type' => $wallet->signature_type,
                'funder' => $wallet->tradingAddress(),
            ];

            $raw = $this->withTransientClobRetry(
                fn () => $client->clob()->account()->getBalanceAllowance($params),
                '读取 Polymarket balance 失败'
            );

            $balance = (string) ($raw['balance'] ?? '0');
            $checks = [
                [
                    'code' => 'collateral_balance',
                    'passed' => $this->isPositiveDecimal($balance),
                    'value' => $balance,
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
                        'failure_code' => 'insufficient_collateral_balance',
                        'checks' => $checks,
                        'balance' => $balance,
                    ];
                }
            }

            return [
                'side' => $side,
                'is_ready' => true,
                'failure_code' => null,
                'checks' => $checks,
                'balance' => $balance,
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

        $credRecord = $wallet->apiCredentials ?: $this->ensureApiCredentials($wallet);
        $creds = $this->decodeApiCredentials($credRecord);
        $privateKey = $this->privateKeyResolver->resolve($wallet);
        $client = $this->factory->makeAuthedClobClient($privateKey, $creds);

        $params = [
            'asset_type' => 'CONDITIONAL',
            'signature_type' => $wallet->signature_type,
            'funder' => $wallet->tradingAddress(),
            'token_id' => trim($tokenId),
        ];

        $raw = $this->withTransientClobRetry(
            fn () => $client->clob()->account()->getBalanceAllowance($params),
            '读取 Polymarket balance 失败'
        );

        $balanceUnits = (string) ($raw['balance'] ?? '0');
        $allowances = is_array($raw['allowances'] ?? null) ? $raw['allowances'] : [];
        $allowanceValues = array_map(static fn ($value) => (string) $value, $allowances);
        $hasBalance = $this->isPositiveInteger($balanceUnits);
        $hasAllowance = collect($allowanceValues)
            ->contains(fn (string $value) => $this->isPositiveInteger($value) || $this->isPositiveDecimal($value));
        $checks = [
            [
                'code' => 'position_balance',
                'passed' => $hasBalance,
                'value' => $balanceUnits,
            ],
            [
                'code' => 'position_allowance',
                'passed' => $hasAllowance,
                'value' => $allowances,
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
            $checks[] = [
                'code' => 'position_required_allowance',
                'passed' => collect($allowanceValues)
                    ->contains(fn (string $value) => bccomp(preg_match('/^\d+(\.\d+)?$/', $value) === 1 ? $value : '0', $requiredUnits, 0) >= 0),
                'value' => $requiredUnits,
            ];
        }

        foreach ($checks as $check) {
            if ($check['passed'] !== true) {
                return [
                    'side' => $side,
                    'is_ready' => false,
                    'failure_code' => str_contains((string) $check['code'], 'allowance')
                        ? 'insufficient_position_allowance'
                        : 'insufficient_position_balance',
                    'checks' => $checks,
                    'balance' => $balanceUnits,
                    'allowances' => $allowances,
                ];
            }
        }

        return [
            'side' => $side,
            'is_ready' => true,
            'failure_code' => null,
            'checks' => $checks,
            'balance' => $balanceUnits,
            'allowances' => $allowances,
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
     * @param array{token_id:string,side:string,price:string,size:string,order_type?:string,defer_exec?:bool,expiration?:string|int|null,salt?:string|int|null,timestamp?:string|int|null,metadata?:string|null,builder?:string|null,outcome?:string|null,market_id?:string|null} $params
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
        $timestamp = $this->normalizeOrderTimestamp($params['timestamp'] ?? null);

        $book = $this->factory->makeReadClient()->clob()->book()->get($tokenId);
        $isNegRisk = (bool) ($book['neg_risk'] ?? false);

        $salt = (string) ($params['salt'] ?? $this->makeSalt());
        $signatureType = (int) ($wallet->signature_type ?? SignatureType::EOA->value);
        $funder = strtolower($wallet->funder_address ?: $wallet->signer_address);
        $signer = strtolower($wallet->signer_address);
        $metadata = $this->normalizeOrderMetadata($params['metadata'] ?? null);
        $metadataForSignature = $metadata;
        $builder = $this->normalizeBytes32($params['builder'] ?? config('pm.builder_code'));

        $amounts = $this->orderSigner->makeAmounts($side, $price, $size);
        $orderSide = $this->orderSigner->sideToInt($side);

        $verifyingContract = $isNegRisk
            ? strtolower((string) config('pm.neg_risk_exchange_contract'))
            : strtolower((string) config('pm.exchange_contract'));

        $order = [
            'salt' => $salt,
            'maker' => $funder,
            'signer' => $signer,
            'tokenId' => $tokenId,
            'makerAmount' => $amounts['makerAmount'],
            'takerAmount' => $amounts['takerAmount'],
            'side' => $orderSide,
            'signatureType' => $signatureType,
            'timestamp' => $timestamp,
            'metadata' => $metadataForSignature,
            'builder' => $builder,
            'verifyingContract' => $verifyingContract,
        ];

        $order['signature'] = $this->orderSigner->sign($order, $privateKey);

        $requestOrder = [
            'salt' => (int) $salt,
            'maker' => $funder,
            'signer' => $signer,
            'tokenId' => (string) $tokenId,
            'makerAmount' => (string) $amounts['makerAmount'],
            'takerAmount' => (string) $amounts['takerAmount'],
            'side' => $side,
            'signatureType' => $signatureType,
            'timestamp' => (string) $timestamp,
            'metadata' => $metadata,
            'builder' => $builder,
            'expiration' => (string) $expiration,
            'signature' => $order['signature'],
        ];

        $payload = [
            'order' => $requestOrder,
            'owner' => $creds->apiKey,
            'orderType' => $orderType,
            'deferExec' => $deferExec,
        ];

        if ((bool) ($params['debug_payload'] ?? false)) {
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
                        'timestamp' => $timestamp,
                    ],
                ],
                'response' => ['debug_only' => true],
            ];
        }

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
                    'timestamp' => $timestamp,
                ],
            ],
            'response' => $response,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getAssetAllowanceStatus(PmCustodyWallet $wallet, string $assetType, ?string $tokenId = null, bool $refresh = false): array
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

        if ($refresh) {
            try {
                $this->withTransientClobRetry(
                    fn () => $client->clob()->account()->updateBalanceAllowance($params),
                    '刷新 Polymarket allowance 失败'
                );
            } catch (\Throwable $e) {
                if (!$this->isClobMissingEndpointError($e)) {
                    throw $e;
                }
            }
        }

        $raw = $this->withTransientClobRetry(
            fn () => $client->clob()->account()->getBalanceAllowance($params),
            '读取 Polymarket allowance 失败'
        );

        if (strtoupper($assetType) !== self::ASSET_COLLATERAL) {
            return $raw;
        }

        $rawAllowances = is_array($raw['allowances'] ?? null) ? $raw['allowances'] : [];
        $hasPositiveAllowance = false;
        foreach ($rawAllowances as $value) {
            $value = (string) $value;
            if ($this->isPositiveDecimal($value) || $this->isPositiveInteger($value)) {
                $hasPositiveAllowance = true;
                break;
            }
        }

        if ($hasPositiveAllowance) {
            return $raw;
        }

        try {
            $chainAllowances = $this->readCollateralAllowancesFromChain($wallet);
            if ($chainAllowances !== []) {
                $raw['allowances'] = $chainAllowances;
            }
        } catch (\Throwable) {
        }

        return $raw;
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
            $book = $this->withTransientClobRetry(
                fn () => $this->factory->makeReadClient()->clob()->book()->get($tokenId),
                '读取 Polymarket orderbook 失败'
            );
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'No orderbook exists for the requested token id')) {
                return false;
            }

            throw $e;
        }

        return is_array($book) && $book !== [];
    }

    private function normalizeOrderMetadata(mixed $value = null): string
    {
        if ($value === null) {
            return '0x0000000000000000000000000000000000000000000000000000000000000000';
        }

        $string = (string) $value;
        if ($string === '') {
            return '0x0000000000000000000000000000000000000000000000000000000000000000';
        }

        return $this->normalizeBytes32($string);
    }

    private function normalizeOrderTimestamp(mixed $value = null): string
    {
        if ($value !== null) {
            $string = trim((string) $value);
            if ($string !== '' && preg_match('/^\d+$/', $string) === 1) {
                return strlen($string) >= 13 ? $string : bcmul($string, '1000', 0);
            }
        }

        return (string) ((int) floor(microtime(true) * 1000));
    }

    private function normalizeBytes32(mixed $value = null): string
    {
        $hex = strtolower(trim((string) ($value ?? '')));
        if ($hex === '') {
            return '0x0000000000000000000000000000000000000000000000000000000000000000';
        }

        $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
        if ($hex === '') {
            return '0x0000000000000000000000000000000000000000000000000000000000000000';
        }

        if (!ctype_xdigit($hex) || strlen($hex) > 64) {
            throw new \InvalidArgumentException('bytes32 字段必须是 32 字节十六进制字符串');
        }

        return '0x' . str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    private function makeSalt(): string
    {
        return (string) random_int(1, 9007199254740991);
    }

    public function wrapCollateralToPusd(PmCustodyWallet $wallet): array
    {
        $legacyToken = trim((string) config('pm.legacy_collateral_token'));
        $collateralToken = trim((string) config('pm.collateral_token'));
        $onramp = trim((string) config('pm.collateral_onramp_contract'));
        $owner = strtolower((string) $wallet->tradingAddress());

        if ($legacyToken === '' || $collateralToken === '' || $onramp === '' || $owner === '') {
            throw new \RuntimeException('pUSD wrap 配置不完整');
        }

        $beforePusd = $this->readErc20Balance($collateralToken, $owner);
        $beforeLegacy = $this->readErc20Balance($legacyToken, $owner);
        if (bccomp($beforeLegacy, '0', 0) <= 0) {
            return [
                'wrapped' => false,
                'reason' => 'no_legacy_collateral_balance',
                'legacy_balance' => $beforeLegacy,
                'pusd_balance' => $beforePusd,
                'legacy_token' => $legacyToken,
                'collateral_token' => $collateralToken,
                'onramp' => $onramp,
            ];
        }

        $privateKey = $this->privateKeyResolver->resolve($wallet);
        $credential = Credential::fromKey(ltrim($privateKey, '0x'));
        $from = strtolower($credential->getAddress());
        $rpcUrl = trim((string) config('pm.polygon_rpc_url'));
        $chainId = (int) config('pm.chain_id', 137);
        $gasPriceHex = (string) $this->rpc($rpcUrl, 'eth_gasPrice', []);
        $nonce = $this->rpcQuantityToInt($this->rpc($rpcUrl, 'eth_getTransactionCount', [$from, 'pending']));
        $txHashes = [];

        $allowanceData = '0xdd62ed3e'
            . $this->padHex($from)
            . $this->padHex($onramp);
        $allowanceHex = $this->rpc($rpcUrl, 'eth_call', [[
            'to' => $legacyToken,
            'data' => $allowanceData,
        ], 'latest']);
        $allowance = $this->rpcHexToDecimalString($allowanceHex);

        if (bccomp($allowance, $beforeLegacy, 0) < 0) {
            $approveData = '0x095ea7b3'
                . $this->padHex(substr(strtolower($onramp), 2))
                . $this->padHex($this->decToHex($beforeLegacy));
            $approveRaw = [
                'nonce' => $this->toRpcHex($nonce),
                'gasPrice' => $gasPriceHex,
                'gasLimit' => $this->toRpcHex(90000),
                'to' => $legacyToken,
                'value' => $this->toRpcHex(0),
                'data' => $approveData,
                'chainId' => $chainId,
            ];
            $approveSigned = $credential->signTransaction($approveRaw);
            $approveTx = (string) $this->rpc($rpcUrl, 'eth_sendRawTransaction', [$approveSigned]);
            $this->waitForReceipt($approveTx);
            $txHashes[] = ['type' => 'approve', 'tx_hash' => $approveTx];
            $nonce++;
        }

        $wrapData = '0x62355638'
            . $this->padHex(substr(strtolower($legacyToken), 2))
            . $this->padHex(substr(strtolower($from), 2))
            . $this->padHex($this->decToHex($beforeLegacy));
        $wrapRaw = [
            'nonce' => $this->toRpcHex($nonce),
            'gasPrice' => $gasPriceHex,
            'gasLimit' => $this->toRpcHex(180000),
            'to' => $onramp,
            'value' => $this->toRpcHex(0),
            'data' => $wrapData,
            'chainId' => $chainId,
        ];
        $wrapSigned = $credential->signTransaction($wrapRaw);
        $wrapTx = (string) $this->rpc($rpcUrl, 'eth_sendRawTransaction', [$wrapSigned]);
        $this->waitForReceipt($wrapTx);
        $txHashes[] = ['type' => 'wrap', 'tx_hash' => $wrapTx];

        $afterPusd = $this->readErc20Balance($collateralToken, $owner);
        $afterLegacy = $this->readErc20Balance($legacyToken, $owner);

        return [
            'wrapped' => true,
            'legacy_balance_before' => $beforeLegacy,
            'legacy_balance_after' => $afterLegacy,
            'pusd_balance_before' => $beforePusd,
            'pusd_balance_after' => $afterPusd,
            'legacy_token' => $legacyToken,
            'collateral_token' => $collateralToken,
            'onramp' => $onramp,
            'tx_hashes' => $txHashes,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function ensureWrappedCollateral(PmCustodyWallet $wallet): void
    {
        $legacyToken = trim((string) config('pm.legacy_collateral_token'));
        $owner = strtolower((string) $wallet->tradingAddress());
        if ($legacyToken === '' || $owner === '') {
            return;
        }

        $legacyBalance = $this->readErc20Balance($legacyToken, $owner);
        // 只有旧资产余额大于 20（按 6 位精度即 20_000_000）时，才尝试自动 wrap。
        if (bccomp($legacyBalance, '20000000', 0) !== 1) {
            return;
        }

        try {
            $this->wrapCollateralToPusd($wallet);
        } catch (\Throwable) {
            // 自动 wrap 失败时不中断 BUY 下单链路，后续继续按当前 collateral 状态做 readiness 检查。
            return;
        }
    }

    public function getWalletAssetBalances(PmCustodyWallet $wallet): array
    {
        $owner = strtolower((string) $wallet->tradingAddress());
        $legacyToken = trim((string) config('pm.legacy_collateral_token'));
        $collateralToken = trim((string) config('pm.collateral_token'));

        $usdcERaw = $legacyToken !== '' && $owner !== '' ? $this->readErc20Balance($legacyToken, $owner) : '0';
        $pusdRaw = $collateralToken !== '' && $owner !== '' ? $this->readErc20Balance($collateralToken, $owner) : '0';

        return [
            'usdc_e_raw' => $usdcERaw,
            'pusd_raw' => $pusdRaw,
            'usdc_e' => bcdiv($usdcERaw, '1000000', 6),
            'pusd' => bcdiv($pusdRaw, '1000000', 6),
            'balance_total' => bcdiv(bcadd($usdcERaw, $pusdRaw, 0), '1000000', 6),
            'legacy_token' => $legacyToken,
            'collateral_token' => $collateralToken,
            'wallet_address' => $owner,
        ];
    }

    private function readErc20Balance(string $token, string $owner): string
    {
        $rpcUrl = trim((string) config('pm.polygon_rpc_url'));
        if ($rpcUrl === '' || $token === '' || $owner === '') {
            return '0';
        }

        $data = '0x70a08231' . $this->padHex($owner);
        $result = $this->rpc($rpcUrl, 'eth_call', [[
            'to' => strtolower($token),
            'data' => $data,
        ], 'latest']);

        return $this->rpcHexToDecimalString($result);
    }

    private function waitForReceipt(string $txHash, int $maxAttempts = 20): void
    {
        $rpcUrl = trim((string) config('pm.polygon_rpc_url'));
        for ($i = 0; $i < $maxAttempts; $i++) {
            $receipt = $this->rpc($rpcUrl, 'eth_getTransactionReceipt', [$txHash]);
            if (is_array($receipt) && !empty($receipt['status'])) {
                if ($this->rpcQuantityToInt((string) $receipt['status']) !== 1) {
                    throw new \RuntimeException('链上交易执行失败: ' . $txHash);
                }
                return;
            }
            usleep(1000000);
        }

        throw new \RuntimeException('等待链上交易确认超时: ' . $txHash);
    }

    private function decToHex(string $decimal): string
    {
        if ($decimal === '0') {
            return '0';
        }

        $hex = '';
        while (bccomp($decimal, '0', 0) > 0) {
            $mod = (int) bcmod($decimal, '16');
            $hex = dechex($mod) . $hex;
            $decimal = bcdiv($decimal, '16', 0);
        }

        return $hex;
    }

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

    private function isClobMissingEndpointError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, '404 not found')
            || str_contains($message, '/update-balance-allowance');
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

    /**
     * @return array<string,string>
     */
    private function readCollateralAllowancesFromChain(PmCustodyWallet $wallet): array
    {
        $rpcUrl = trim((string) config('pm.polygon_rpc_url'));
        $token = trim((string) config('pm.collateral_token'));
        $owner = trim((string) $wallet->tradingAddress());
        if ($rpcUrl === '' || $token === '' || $owner === '') {
            return [];
        }

        $allowances = [];
        foreach ($this->approvalSpenders() as $spender) {
            $data = '0xdd62ed3e'
                . $this->padHex($owner)
                . $this->padHex($spender);
            $result = $this->rpc($rpcUrl, 'eth_call', [[
                'to' => $token,
                'data' => $data,
            ], 'latest']);
            $allowances[$spender] = $this->rpcHexToDecimalString($result);
        }

        return $allowances;
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

    private function rpcHexToDecimalString(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return '0';
        }

        $hex = strtolower(trim($value));
        $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            return '0';
        }

        $decimal = '0';
        foreach (str_split($hex) as $char) {
            $decimal = bcmul($decimal, '16', 0);
            $decimal = bcadd($decimal, (string) hexdec($char), 0);
        }

        return $decimal;
    }

    private function toRpcHex(int $value): string
    {
        return '0x' . dechex($value);
    }
}
