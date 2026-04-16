<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCustodyWallet;
use App\Services\Pm\PmPrivateKeyResolver;
use App\Services\Pm\PolygonRpcService;
use EthTool\Credential;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class PmClaimPositionCommand extends Command
{
    protected $signature = 'pm:claim-position
                            {address? : 资金方地址或托管钱包地址}
                            {--condition-id= : 指定要领取的 Condition ID}
                            {--dry-run : 只查询不执行领取}
                            {--include-losing : 包含已输但链上仍可 redeem 的持仓}
                            {--scan-all : 扫描所有钱包并自动结算}
                            {--min-age=3600 : 最小订单年龄（秒），默认 3600 秒（1 小时）}';
    protected $description = '查询并领取指定地址的 Polymarket 持仓奖励';

    public function handle(PmPrivateKeyResolver $resolver, PolygonRpcService $rpcService): int
    {
        $scanAll = $this->option('scan-all');

        // 如果是扫描所有钱包模式
        if ($scanAll) {
            return $this->scanAllWallets($resolver, $rpcService);
        }

        // 单个地址模式
        $address = $this->argument('address');
        if (!$address) {
            $this->error('❌ 请提供地址参数，或使用 --scan-all 扫描所有钱包');
            return 1;
        }

        $address = strtolower(trim($address));
        $conditionId = $this->option('condition-id');
        $dryRun = $this->option('dry-run');
        $includeLosing = (bool) $this->option('include-losing');

        $this->info("========== 查询持仓 ==========\n");

        // 1. 查找托管钱包
        $wallet = PmCustodyWallet::where('funder_address', $address)
            ->orWhere('signer_address', $address)
            ->orWhere('address', $address)
            ->first();

        if (!$wallet) {
            $this->error("❌ 未找到地址 {$address} 对应的托管钱包");
            return 1;
        }

        $this->line("托管钱包地址: {$wallet->address}");
        $this->line("Signer 地址: {$wallet->signer_address}");
        $this->line("Funder 地址: {$wallet->funder_address}\n");

        // 2. 查询持仓
        $this->info("🔍 查询持仓...\n");

        try {
            $positionsJson = file_get_contents("https://data-api.polymarket.com/positions?user={$wallet->signer_address}");
            $positions = json_decode($positionsJson, true);
            //&enum=TITLE&sortDirection=DESC
        } catch (\Exception $e) {
            $this->error("❌ 查询持仓失败: " . $e->getMessage());
            return 1;
        }

        if (empty($positions)) {
            $this->warn("⚠️  未找到任何持仓");
            return 0;
        }

        // 3. 显示持仓列表
        $claimablePositions = [];
        $totalValue = 0;

        $this->line("找到 " . count($positions) . " 个持仓:\n");
        $this->line(str_repeat('=', 120));

        foreach ($positions as $index => $pos) {

            $currentValue = (float) ($pos['currentValue'] ?? 0);
            $isRedeemable = (bool) ($pos['redeemable'] ?? false);
            $hasChainBalance = $this->hasChainTokenBalance($wallet, (string) ($pos['asset'] ?? ''));
            $canRedeemLosing = $includeLosing && $currentValue <= 0 && $isRedeemable && $hasChainBalance;
            $isClaimable = (($currentValue > 0 && $isRedeemable) || $canRedeemLosing) && $hasChainBalance;
            $isLaggingRedeemed = $currentValue > 0 && $isRedeemable && !$hasChainBalance;

            if ($isClaimable) {
                $claimablePositions[] = $pos;
                $totalValue += max($currentValue, 0);
            }

            $status = $isClaimable
                ? ($canRedeemLosing ? '♻️ 可兑换(已输)' : '✅ 可领取')
                : ($isLaggingRedeemed
                    ? '☑️ 已兑换(接口延迟)'
                    : ($currentValue > 0 ? '⏳ 未结算' : '❌ 已输'));

            $this->line(sprintf(
                "[%d] %s %s",
                $index + 1,
                $status,
                $pos['title'] ?? 'Unknown'
            ));
            $slug = $pos['slug'] ?? '';
            if (preg_match('/-(\d+)$/', $slug, $matches)) {
                $orderTime = (int) $matches[1];
                $this->line("    订单时间: " . date('Y-m-d H:i:s', $orderTime));
            }

            // $this->line("    市场: " . ($pos['market'] ?? 'N/A'));
            $this->line("    下注方向: " . ($pos['outcome'] ?? 'N/A'));
            // $this->line("    投入成本: $" . number_format($pos['initialValue'] ?? 0, 2));
            $this->line("    当前价值: $" . number_format($currentValue, 2));
            $this->line("    盈亏: $" . number_format($pos['cashPnl'] ?? 0, 2) . " (" . number_format($pos['percentPnl'] ?? 0, 2) . "%)");
            if ($canRedeemLosing) {
                $this->line("    说明: 已输但链上仍有可兑换余额");
            }

            if ($isClaimable) {
                $this->line("    Condition ID: " . ($pos['conditionId'] ?? 'N/A'));
                $this->line("    Token ID: " . ($pos['asset'] ?? 'N/A'));
            }

            $this->line(str_repeat('-', 120));
        }

        $this->newLine();
        $this->info("📊 汇总:");
        $this->line("总持仓数: " . count($positions));
        $this->line("可领取持仓: " . count($claimablePositions));
        $this->line("可领取总价值: $" . number_format($totalValue, 2));
        $this->newLine();

        if (empty($claimablePositions)) {
            $this->warn("⚠️  没有可领取的持仓");
            return 0;
        }

        // 4. 如果指定了 condition-id，只领取该持仓
        if ($conditionId) {
            $conditionId = strtolower(trim($conditionId));
            $claimablePositions = array_filter($claimablePositions, function ($pos) use ($conditionId) {
                return strtolower($pos['conditionId'] ?? '') === $conditionId;
            });

            if (empty($claimablePositions)) {
                $this->error("❌ 未找到 Condition ID: {$conditionId} 的可领取持仓");
                return 1;
            }
        }

        // 5. Dry run 模式，只查询不执行
        if ($dryRun) {
            $this->info("🔍 Dry Run 模式，不执行领取操作");
            return 0;
        }

        // 6. 循环领取所有可领取的持仓
        $successCount = 0;
        $failCount = 0;
        $totalCount = count($claimablePositions);

        $this->newLine();
        $this->info("========== 开始领取 {$totalCount} 个持仓 ==========");
        $this->newLine();

        foreach ($claimablePositions as $index => $position) {
            $this->info("[" . ($index + 1) . "/{$totalCount}] 准备领取:");
            $this->line("市场: " . ($position['title'] ?? 'Unknown'));
            $label = ((float) ($position['currentValue'] ?? 0)) > 0 ? '金额' : '可返还金额';
            $this->line($label . ': $' . number_format($position['currentValue'], 2));
            $this->line("Condition ID: " . ($position['conditionId'] ?? 'N/A'));
            $this->newLine();

            try {
                $result = $this->claimPosition($wallet, $position, $resolver, $rpcService);

                if ($result['success']) {
                    $successCount++;
                    $this->info("✅ 领取成功（链上执行成功且余额校验通过）");
                    $this->line("交易哈希: " . $result['tx_hash']);
                    $this->line("查看交易: https://polygonscan.com/tx/" . $result['tx_hash']);
                    $this->line("Receipt Status: " . var_export($result['receipt_status'] ?? null, true));
                    $this->line("Gas 使用: " . number_format($result['gas_used'] ?? 0));
                    $this->line("区块号: " . number_format($result['block_number'] ?? 0));
                    if (array_key_exists('before_balance', $result)) {
                        $this->line("领取前 Token 余额: " . (($result['before_balance'] ?? null) === null ? 'N/A' : (string) $result['before_balance']));
                    }
                    if (array_key_exists('after_balance', $result)) {
                        $this->line("领取后 Token 余额: " . (($result['after_balance'] ?? null) === null ? 'N/A' : (string) $result['after_balance']));
                    }
                } else {
                    $failCount++;
                    $this->error("❌ 领取失败: " . ($result['error'] ?? 'Unknown error'));
                    if (!empty($result['tx_hash'])) {
                        $this->line("交易哈希: " . $result['tx_hash']);
                    }
                    if (array_key_exists('receipt_status', $result)) {
                        $this->line("Receipt Status: " . var_export($result['receipt_status'], true));
                    }
                    if (array_key_exists('before_balance', $result)) {
                        $this->line("领取前 Token 余额: " . (($result['before_balance'] ?? null) === null ? 'N/A' : (string) $result['before_balance']));
                    }
                    if (array_key_exists('after_balance', $result)) {
                        $this->line("领取后 Token 余额: " . (($result['after_balance'] ?? null) === null ? 'N/A' : (string) $result['after_balance']));
                    }
                }
            } catch (\Exception $e) {
                $failCount++;
                $this->error("❌ 领取失败: " . $e->getMessage());
            }

            // 如果不是最后一个，等待 3 秒再继续
            if ($index < $totalCount - 1) {
                $this->newLine();
                $this->line("⏳ 等待 3 秒后继续下一个...");
                sleep(3);
                $this->newLine();
                $this->line(str_repeat('=', 120));
                $this->newLine();
            }
        }

        // 7. 显示汇总
        $this->newLine();
        $this->info("========== 领取完成 ==========");
        $this->line("总数: {$totalCount}");
        $this->line("成功: {$successCount}");
        $this->line("失败: {$failCount}");

        return $failCount > 0 ? 1 : 0;
    }

    private function claimPosition(PmCustodyWallet $wallet, array $position, PmPrivateKeyResolver $resolver, PolygonRpcService $rpcService): array
    {
        $this->info("\n========== 执行领取 ==========\n");

        // 1. 解析私钥
        try {
            $privateKey = $resolver->resolve($wallet);
            $this->line("✅ 私钥解析成功");
        } catch (\Exception $e) {
            throw new \RuntimeException("私钥解析失败: " . $e->getMessage());
        }

        // 2. 准备参数
        $conditionId = strtolower($position['conditionId'] ?? '');
        $collateralToken = config('pm.claim_collateral_token', config('pm.collateral_token'));
        $ctfContract = config('pm.ctf_contract');
        $negRiskAdapterContract = config('pm.neg_risk_adapter_contract');
        $parentCollectionId = '0x0000000000000000000000000000000000000000000000000000000000000000';
        $isNegativeRisk = (bool) ($position['negativeRisk'] ?? false);
        $indexSets = $this->resolveIndexSetsFromPosition($position);

        $this->line("合约地址: " . ($isNegativeRisk ? $negRiskAdapterContract : $ctfContract));
        $this->line("Condition ID: {$conditionId}");
        $this->line("Negative Risk: " . ($isNegativeRisk ? 'yes' : 'no'));

        // 3. 编码 calldata
        $calldata = $isNegativeRisk
            ? $this->encodeNegRiskRedeemCalldata($position)
            : $this->encodeCtfRedeemCalldata((string) $collateralToken, $parentCollectionId, $conditionId, $indexSets);

        // 4. 准备交易
        $chainId = (int) config('pm.chain_id', 137);
        $credential = Credential::fromKey(ltrim($privateKey, '0x'));
        $from = strtolower($credential->getAddress());
        $tokenId = (string) ($position['asset'] ?? '');
        $beforeBalance = $this->getChainTokenBalance($wallet, $tokenId, $rpcService, true);

        $this->line("从地址: {$from}");
        if ($beforeBalance !== null) {
            $this->line("领取前 Token 余额: {$beforeBalance}");
        }

        // 5. 获取 nonce 和 gas price
        $nonce = $rpcService->getTransactionCount($from, 'pending');
        $baseGasPrice = $rpcService->rpcQuantityToInt($rpcService->gasPrice());
        $safeGasPrice = (int) ($baseGasPrice * 1.2);
        $gasPriceHex = $rpcService->toRpcHex($safeGasPrice);
        $gasLimit = $this->resolveGasLimit($rpcService, [
            'from' => $from,
            'to' => $isNegativeRisk ? $negRiskAdapterContract : $ctfContract,
            'value' => '0x0',
            'data' => $calldata,
        ], $isNegativeRisk);

        $this->line("Nonce: {$nonce}");
        $this->line("Gas Price: " . number_format($safeGasPrice / 1e9, 2) . " Gwei");
        $this->line("Gas Limit: {$gasLimit}");
        $this->line("Index Sets: [" . implode(', ', $indexSets) . "]");

        // 6. 发送前先做 eth_call 预检查，避免错误参数直接上链
        $rawCall = [
            'from' => $from,
            'to' => $isNegativeRisk ? $negRiskAdapterContract : $ctfContract,
            'value' => '0x0',
            'data' => $calldata,
        ];

        try {
            $rpcService->call('eth_call', [$rawCall, 'latest']);
            $this->line("✅ eth_call 预检查通过");
        } catch (\Exception $e) {
            throw new \RuntimeException('eth_call 预检查失败: ' . $e->getMessage());
        }

        // 7. 签名并发送交易
        $raw = [
            'nonce' => $rpcService->toRpcHex($nonce),
            'gasPrice' => $gasPriceHex,
            'gasLimit' => $rpcService->toRpcHex($gasLimit),
            'to' => $isNegativeRisk ? $negRiskAdapterContract : $ctfContract,
            'value' => $rpcService->toRpcHex(0),
            'data' => $calldata,
            'chainId' => $chainId,
        ];

        $this->line("\n🚀 发送交易...");
        $signed = $credential->signTransaction($raw);
        $txHash = $rpcService->sendRawTransaction($signed);

        $this->line("✅ 交易已提交: {$txHash}");

        // 8. 等待确认
        $this->line("\n⏳ 等待交易确认...");
        $bar = $this->output->createProgressBar(30);
        $bar->start();

        for ($i = 0; $i < 30; $i++) {
            sleep(2);
            $bar->advance();

            try {
                $receipt = $rpcService->getTransactionReceipt($txHash);
                if ($receipt !== null) {
                    $bar->finish();
                    $this->newLine();

                    $rawStatus = $receipt['status'] ?? null;
                    $normalizedStatus = $rpcService->normalizeReceiptStatus($rawStatus);
                    $this->line('Receipt Status: ' . var_export($rawStatus, true));

                    if ($rpcService->receiptStatusSucceeded($receipt)) {
                        $this->line('✅ 链上执行成功，开始校验领取后余额');

                        $afterBalance = $this->getChainTokenBalance($wallet, $tokenId, $rpcService, false);
                        if ($afterBalance !== null) {
                            $this->line("领取后 Token 余额: {$afterBalance}");
                        }

                        $verified = $this->verifyClaimedPositionBalance($beforeBalance, $afterBalance);
                        if (!$verified) {
                            return [
                                'success' => false,
                                'error' => '链上交易成功，但领取后余额未下降，未确认业务领取成功',
                                'tx_hash' => $txHash,
                                'block_number' => isset($receipt['blockNumber']) ? hexdec($receipt['blockNumber']) : 0,
                                'gas_used' => isset($receipt['gasUsed']) ? hexdec($receipt['gasUsed']) : 0,
                                'receipt_status' => $rawStatus,
                                'normalized_receipt_status' => $normalizedStatus,
                                'before_balance' => $beforeBalance,
                                'after_balance' => $afterBalance,
                            ];
                        }

                        return [
                            'success' => true,
                            'tx_hash' => $txHash,
                            'block_number' => isset($receipt['blockNumber']) ? hexdec($receipt['blockNumber']) : 0,
                            'gas_used' => isset($receipt['gasUsed']) ? hexdec($receipt['gasUsed']) : 0,
                            'receipt_status' => $rawStatus,
                            'normalized_receipt_status' => $normalizedStatus,
                            'before_balance' => $beforeBalance,
                            'after_balance' => $afterBalance,
                        ];
                    }

                    return [
                        'success' => false,
                        'error' => '交易失败',
                        'tx_hash' => $txHash,
                        'receipt_status' => $rawStatus,
                        'normalized_receipt_status' => $normalizedStatus,
                    ];
                }
            } catch (\Exception $e) {
                // 继续等待
            }
        }

        $bar->finish();
        $this->newLine();

        return [
            'success' => false,
            'error' => '交易确认超时，请手动查看: https://polygonscan.com/tx/' . $txHash,
            'tx_hash' => $txHash,
        ];
    }

    private function encodeCtfRedeemCalldata(string $collateralToken, string $parentCollectionId, string $conditionId, array $indexSets): string
    {
        $selector = '0x01b7037c'; // redeemPositions(address,bytes32,bytes32,uint256[])
        $head = '';
        $head .= $this->encodeAddress($collateralToken);
        $head .= $this->encodeBytes32($parentCollectionId);
        $head .= $this->encodeBytes32($conditionId);
        $head .= $this->encodeUint256(128);

        $tail = $this->encodeUint256(count($indexSets));
        foreach ($indexSets as $indexSet) {
            $tail .= $this->encodeUint256($indexSet);
        }

        return $selector . $head . $tail;
    }

    private function encodeNegRiskRedeemCalldata(array $position): string
    {
        $selector = '0xdbeccb23'; // redeemPositions(bytes32,uint256[])
        $conditionId = strtolower((string) ($position['conditionId'] ?? ''));
        $amounts = $this->resolveNegRiskRedeemAmounts($position);

        $head = '';
        $head .= $this->encodeBytes32($conditionId);
        $head .= $this->encodeUint256(64);

        $tail = $this->encodeUint256(count($amounts));
        foreach ($amounts as $amount) {
            $tail .= $this->encodeDecimalUint256((string) $amount);
        }

        return $selector . $head . $tail;
    }

    private function resolveNegRiskRedeemAmounts(array $position): array
    {
        $size = (string) ($position['size'] ?? '0');
        $scaledAmount = $this->decimalToTokenUnits($size);
        $outcomeIndex = Arr::get($position, 'outcomeIndex');

        if (is_numeric($outcomeIndex) && (int) $outcomeIndex === 1) {
            return ['0', $scaledAmount];
        }

        return [$scaledAmount, '0'];
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

    private function encodeAddress(string $value): string
    {
        return str_pad(strtolower(ltrim($value, '0x')), 64, '0', STR_PAD_LEFT);
    }

    private function encodeBytes32(string $value): string
    {
        return str_pad(strtolower(ltrim($value, '0x')), 64, '0', STR_PAD_LEFT);
    }

    private function encodeUint256(int $value): string
    {
        return str_pad(dechex($value), 64, '0', STR_PAD_LEFT);
    }

    private function encodeDecimalUint256(string $value): string
    {
        $hex = $this->decToHex($value);
        return str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    /**
     * 根据持仓接口返回动态计算 indexSets
     * 优先使用 outcomeIndex；否则回退到二元市场默认 [1,2]
     *
     * @return array<int,int>
     */
    private function resolveIndexSetsFromPosition(array $position): array
    {
        $outcomeIndex = $position['outcomeIndex'] ?? null;
        if ($outcomeIndex !== null && is_numeric($outcomeIndex)) {
            $index = (int) $outcomeIndex;
            if ($index >= 0 && $index <= 255) {
                return [1 << $index];
            }
        }

        return [1, 2];
    }

    /**
     * 检查链上是否仍持有该 CTF token，避免 Polymarket 接口延迟导致误判为“可领取”
     */
    private function hasChainTokenBalance(PmCustodyWallet $wallet, string $tokenId): bool
    {
        $balance = $this->getChainTokenBalance($wallet, $tokenId, null, true);
        return $balance === null ? true : $balance > 0;
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
            $method = '0x00fdd58e'; // balanceOf(address,uint256)
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

    private function verifyClaimedPositionBalance(?int $beforeBalance, ?int $afterBalance): bool
    {
        if ($beforeBalance === null || $afterBalance === null) {
            return false;
        }

        return $afterBalance < $beforeBalance;
    }

    private function resolveGasLimit(PolygonRpcService $rpcService, array $tx, bool $isNegativeRisk): int
    {
        $fallback = $isNegativeRisk ? 400000 : 220000;

        try {
            $estimated = $rpcService->call('eth_estimateGas', [$tx]);
            $estimatedInt = $rpcService->rpcQuantityToInt($estimated);
            if ($estimatedInt <= 0) {
                return $fallback;
            }

            $buffered = (int) ceil($estimatedInt * 1.3);
            return max($buffered, $fallback);
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

    /**
     * 扫描所有钱包并自动结算
     */
    private function scanAllWallets(PmPrivateKeyResolver $resolver, PolygonRpcService $rpcService): int
    {
        $minAge = (int) $this->option('min-age');
        $dryRun = $this->option('dry-run');
        $includeLosing = (bool) $this->option('include-losing');
        $now = time();
        $cutoffTime = $now - $minAge;

        $this->info("========== 扫描所有钱包 ==========\n");
        $this->line("最小订单年龄: " . ($minAge / 3600) . " 小时");
        $this->line("截止时间: " . date('Y-m-d H:i:s', $cutoffTime));
        $this->line("Dry Run: " . ($dryRun ? '是' : '否'));
        $this->newLine();

        // 1. 获取所有钱包
        $wallets = PmCustodyWallet::whereNotNull('en_private_key')
            ->where('en_private_key', '!=', '')
            ->get();

        $this->info("找到 " . $wallets->count() . " 个钱包\n");

        if ($wallets->isEmpty()) {
            $this->warn("⚠️  没有找到任何钱包");
            return 0;
        }

        // 2. 统计变量
        $totalWallets = 0;
        $totalPositions = 0;
        $totalClaimable = 0;
        $totalClaimed = 0;
        $totalFailed = 0;
        $totalValue = 0;

        // 3. 遍历所有钱包
        foreach ($wallets as $wallet) {
            $totalWallets++;
            $signerAddress = strtolower($wallet->signer_address);

            $this->line(str_repeat('=', 120));
            $this->info("[{$totalWallets}/{$wallets->count()}] 钱包: {$signerAddress}");
            $this->line("Funder: {$wallet->funder_address}");
            $this->newLine();

            try {
                // 查询持仓
                $positionsJson = @file_get_contents("https://data-api.polymarket.com/positions?user={$signerAddress}");
                if (!$positionsJson) {
                    $this->warn("⚠️  查询持仓失败，跳过");
                    $this->newLine();
                    continue;
                }

                $positions = json_decode($positionsJson, true);
                if (empty($positions)) {
                    $this->line("无持仓");
                    $this->newLine();
                    continue;
                }

                $totalPositions += count($positions);
                $this->line("找到 " . count($positions) . " 个持仓");

                // 仅按时间条件筛选持仓，并额外校验链上 token 余额，避免接口延迟导致已兑换仍显示可领取
                $claimablePositions = [];
                foreach ($positions as $pos) {
                    $currentValue = (float) ($pos['currentValue'] ?? 0);
                    $isRedeemable = (bool) ($pos['redeemable'] ?? false);
                    $hasChainBalance = $this->hasChainTokenBalance($wallet, (string) ($pos['asset'] ?? ''));

                    // 已兑换但接口未刷新
                    if ($currentValue > 0 && $isRedeemable && !$hasChainBalance) {
                        $this->line("  ☑️ 已兑换(接口延迟): " . ($pos['title'] ?? 'Unknown') . " - $" . number_format($currentValue, 2));
                        continue;
                    }

                    // 从 slug 中提取时间戳
                    $slug = $pos['slug'] ?? '';
                    if (preg_match('/-(\d+)$/', $slug, $matches)) {
                        $orderTime = (int) $matches[1];
                        $age = $now - $orderTime;

                        // 只处理超过最小年龄、且接口可领、且链上仍有 token 余额的持仓
                        if ($age >= $minAge && (($currentValue > 0 && $isRedeemable) || ($includeLosing && $currentValue <= 0 && $isRedeemable)) && $hasChainBalance) {
                            $claimablePositions[] = $pos;
                            $totalValue += max($currentValue, 0);
                            $isLosingRedeemable = $includeLosing && $currentValue <= 0 && $isRedeemable;
                            $prefix = $isLosingRedeemable ? '♻️ 可兑换(已输)' : '✅ 可领取';
                            $this->line("  {$prefix}: " . ($pos['title'] ?? 'Unknown') . " - $" . number_format($currentValue, 2) . " (订单时间: " . date('Y-m-d H:i:s', $orderTime) . ", 年龄: " . round($age / 3600, 1) . "h)");
                        } else {
                            $this->line("  ⏳ 太新: " . ($pos['title'] ?? 'Unknown') . " - $" . number_format($currentValue, 2) . " (订单时间: " . date('Y-m-d H:i:s', $orderTime) . ", 年龄: " . round($age / 3600, 1) . "h)");
                        }
                    } else {
                        // 无法提取时间戳，跳过
                        $this->line("  ⚠️  无法提取时间: " . ($pos['title'] ?? 'Unknown') . " - $" . number_format($currentValue, 2));
                    }
                }

                if (empty($claimablePositions)) {
                    $this->line("无符合条件的可领取持仓");
                    $this->newLine();
                    continue;
                }

                $totalClaimable += count($claimablePositions);
                $this->newLine();
                $this->info("符合条件的可领取持仓: " . count($claimablePositions) . " 个");

                // Dry run 模式，不执行领取
                if ($dryRun) {
                    $this->newLine();
                    continue;
                }

                // 执行领取
                $this->newLine();
                foreach ($claimablePositions as $index => $position) {
                    $this->line("[" . ($index + 1) . "/" . count($claimablePositions) . "] 领取: " . ($position['title'] ?? 'Unknown') . " - $" . number_format($position['currentValue'], 2));

                    try {
                        $result = $this->claimPosition($wallet, $position, $resolver, $rpcService);

                        if ($result['success']) {
                            $totalClaimed++;
                            $this->info("✅ 成功（链上执行成功且余额校验通过）- TX: " . $result['tx_hash']);
                        } else {
                            $totalFailed++;
                            $this->error("❌ 失败: " . ($result['error'] ?? 'Unknown'));
                        }
                    } catch (\Exception $e) {
                        $totalFailed++;
                        $this->error("❌ 异常: " . $e->getMessage());
                    }

                    // 等待 3 秒
                    if ($index < count($claimablePositions) - 1) {
                        sleep(3);
                    }
                }

                $this->newLine();
            } catch (\Exception $e) {
                $this->error("❌ 处理钱包失败: " . $e->getMessage());
                $this->newLine();
            }
        }

        // 4. 显示汇总
        $this->line(str_repeat('=', 120));
        $this->info("\n========== 扫描完成 ==========");
        $this->line("扫描钱包数: {$totalWallets}");
        $this->line("总持仓数: {$totalPositions}");
        $this->line("符合条件的可领取持仓: {$totalClaimable}");
        $this->line("可领取总价值: $" . number_format($totalValue, 2));

        if (!$dryRun) {
            $this->line("成功领取: {$totalClaimed}");
            $this->line("失败: {$totalFailed}");
        }

        return 0;
    }
}
