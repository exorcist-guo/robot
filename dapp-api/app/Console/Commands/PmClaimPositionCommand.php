<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCustodyWallet;
use App\Services\Pm\PmPrivateKeyResolver;
use EthTool\Credential;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class PmClaimPositionCommand extends Command
{
    protected $signature = 'pm:claim-position
                            {address? : 资金方地址或托管钱包地址}
                            {--condition-id= : 指定要领取的 Condition ID}
                            {--dry-run : 只查询不执行领取}
                            {--scan-all : 扫描所有钱包并自动结算}
                            {--min-age=3600 : 最小订单年龄（秒），默认 3600 秒（1 小时）}';

    protected $description = '查询并领取指定地址的 Polymarket 持仓奖励';

    public function handle(PmPrivateKeyResolver $resolver): int
    {
        $scanAll = $this->option('scan-all');

        // 如果是扫描所有钱包模式
        if ($scanAll) {
            return $this->scanAllWallets($resolver);
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
            $isClaimable = $currentValue > 0 && ($pos['redeemable'] ?? false);

            if ($isClaimable) {
                $claimablePositions[] = $pos;
                $totalValue += $currentValue;
            }

            $status = $isClaimable ? '✅ 可领取' : ($currentValue > 0 ? '⏳ 未结算' : '❌ 已输');

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
            $this->line("金额: $" . number_format($position['currentValue'], 2));
            $this->line("Condition ID: " . ($position['conditionId'] ?? 'N/A'));
            $this->newLine();

            try {
                $result = $this->claimPosition($wallet, $position, $resolver);

                if ($result['success']) {
                    $successCount++;
                    $this->info("✅ 领取成功!");
                    $this->line("交易哈希: " . $result['tx_hash']);
                    $this->line("查看交易: https://polygonscan.com/tx/" . $result['tx_hash']);
                    $this->line("Gas 使用: " . number_format($result['gas_used']));
                    $this->line("区块号: " . number_format($result['block_number']));
                } else {
                    $failCount++;
                    $this->error("❌ 领取失败: " . ($result['error'] ?? 'Unknown error'));
                    if (!empty($result['tx_hash'])) {
                        $this->line("交易哈希: " . $result['tx_hash']);
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

    private function claimPosition(PmCustodyWallet $wallet, array $position, PmPrivateKeyResolver $resolver): array
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
        $collateralToken = config('pm.collateral_token');
        $ctfContract = config('pm.ctf_contract');
        $parentCollectionId = '0x0000000000000000000000000000000000000000000000000000000000000000';
        $indexSets = [1, 2];

        $this->line("合约地址: {$ctfContract}");
        $this->line("Condition ID: {$conditionId}");

        // 3. 编码 calldata
        $selector = '0x01b7037c'; // redeemPositions
        $head = '';
        $head .= $this->encodeAddress($collateralToken);
        $head .= $this->encodeBytes32($parentCollectionId);
        $head .= $this->encodeBytes32($conditionId);
        $head .= $this->encodeUint256(128);

        $tail = $this->encodeUint256(count($indexSets));
        foreach ($indexSets as $indexSet) {
            $tail .= $this->encodeUint256($indexSet);
        }

        $calldata = $selector . $head . $tail;

        // 4. 准备交易
        $rpcUrl = config('pm.polygon_rpc_url');
        $chainId = (int) config('pm.chain_id', 137);
        $credential = Credential::fromKey(ltrim($privateKey, '0x'));
        $from = strtolower($credential->getAddress());

        $this->line("从地址: {$from}");

        // 5. 获取 nonce 和 gas price
        $nonce = hexdec(ltrim($this->rpc($rpcUrl, 'eth_getTransactionCount', [$from, 'pending']), '0x'));
        $gasPriceHex = $this->rpc($rpcUrl, 'eth_gasPrice', []);
        $baseGasPrice = hexdec(ltrim($gasPriceHex, '0x'));
        $safeGasPrice = (int) ($baseGasPrice * 1.2);
        $gasPriceHex = '0x' . dechex($safeGasPrice);
        $gasLimit = 220000;

        $this->line("Nonce: {$nonce}");
        $this->line("Gas Price: " . number_format($safeGasPrice / 1e9, 2) . " Gwei");
        $this->line("Gas Limit: {$gasLimit}");

        // 6. 签名并发送交易
        $raw = [
            'nonce' => '0x' . dechex($nonce),
            'gasPrice' => $gasPriceHex,
            'gasLimit' => '0x' . dechex($gasLimit),
            'to' => $ctfContract,
            'value' => '0x0',
            'data' => $calldata,
            'chainId' => $chainId,
        ];

        $this->line("\n🚀 发送交易...");
        $signed = $credential->signTransaction($raw);
        $txHash = $this->rpc($rpcUrl, 'eth_sendRawTransaction', [$signed]);

        $this->line("✅ 交易已提交: {$txHash}");

        // 7. 等待确认
        $this->line("\n⏳ 等待交易确认...");
        $bar = $this->output->createProgressBar(30);
        $bar->start();

        for ($i = 0; $i < 30; $i++) {
            sleep(2);
            $bar->advance();

            try {
                $receipt = $this->rpc($rpcUrl, 'eth_getTransactionReceipt', [$txHash]);
                if ($receipt !== null) {
                    $bar->finish();
                    $this->newLine();

                    $status = $receipt['status'] ?? '0x0';
                    if ($status === '0x1') {
                        return [
                            'success' => true,
                            'tx_hash' => $txHash,
                            'block_number' => hexdec($receipt['blockNumber']),
                            'gas_used' => hexdec($receipt['gasUsed']),
                        ];
                    } else {
                        return [
                            'success' => false,
                            'error' => '交易失败',
                            'tx_hash' => $txHash,
                        ];
                    }
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

    private function rpc(string $url, string $method, array $params): mixed
    {
        $client = new Client(['timeout' => 20]);
        $response = $client->post($url, [
            'json' => [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => 1,
            ],
        ]);

        $json = json_decode($response->getBody()->getContents(), true);
        if (!empty($json['error'])) {
            throw new \RuntimeException($json['error']['message'] ?? 'RPC error');
        }

        return $json['result'] ?? null;
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

    /**
     * 扫描所有钱包并自动结算
     */
    private function scanAllWallets(PmPrivateKeyResolver $resolver): int
    {
        $minAge = (int) $this->option('min-age');
        $dryRun = $this->option('dry-run');
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

                // 筛选可领取且符合时间条件的持仓
                $claimablePositions = [];
                foreach ($positions as $pos) {
                    $currentValue = (float) ($pos['currentValue'] ?? 0);
                    $isRedeemable = $pos['redeemable'] ?? false;

                    if ($currentValue <= 0 || !$isRedeemable) {
                        continue;
                    }

                    // 从 slug 中提取时间戳
                    $slug = $pos['slug'] ?? '';
                    if (preg_match('/-(\d+)$/', $slug, $matches)) {
                        $orderTime = (int) $matches[1];
                        $age = $now - $orderTime;

                        // 只处理超过最小年龄的订单
                        if ($age >= $minAge) {
                            $claimablePositions[] = $pos;
                            $totalValue += $currentValue;
                            $this->line("  ✅ 可领取: " . ($pos['title'] ?? 'Unknown') . " - $" . number_format($currentValue, 2) . " (订单时间: " . date('Y-m-d H:i:s', $orderTime) . ", 年龄: " . round($age / 3600, 1) . "h)");
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
                        $result = $this->claimPosition($wallet, $position, $resolver);

                        if ($result['success']) {
                            $totalClaimed++;
                            $this->info("✅ 成功 - TX: " . $result['tx_hash']);
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
