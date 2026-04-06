<?php

namespace App\Console\Commands;

use App\Services\BnbService;
use Illuminate\Console\Command;

class BnbHealthCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bnb:healthcheck {--env=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试 BSC 合约连接状态 (只读)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $envOption = $this->option('env');

        // 如果指定了 env，临时切换
        if ($envOption) {
            $config = BnbService::switchEnv($envOption);
            if (isset($config['error'])) {
                $this->error($config['error']);
                return Command::FAILURE;
            }
            $this->info("已切换到环境: {$envOption}");
        }

        $env = config('bsc.env', 'test');
        $this->info("========================================");
        $this->info("BSC 健康检查 - 当前环境: {$env}");
        $this->info("========================================\n");

        $allPassed = true;

        // 1. 检查 RPC URL
        $this->info('[1/6] 检查 RPC 配置...');
        $rpcUrl = config('bsc.rpc.' . $env);
        if (empty($rpcUrl)) {
            $this->error("   ✗ RPC URL 未配置 (bsc.rpc.{$env})");
            $allPassed = false;
        } else {
            $this->info("   ✓ RPC URL: " . $this->maskApiKey($rpcUrl));
        }

        // 2. 检查合约地址
        $this->info('[2/6] 检查合约地址...');
        $contractAddress = config('bsc.contract_address.' . $env);
        if (empty($contractAddress)) {
            $this->error("   ✗ 合约地址未配置 (bsc.contract_address.{$env})");
            $allPassed = false;
        } elseif (!BnbService::isValidAddress($contractAddress)) {
            $this->error("   ✗ 合约地址格式无效: {$contractAddress}");
            $allPassed = false;
        } else {
            $this->info("   ✓ 合约地址: {$contractAddress}");
        }

        // 3. 检查 Chain ID
        $this->info('[3/6] 检查 Chain ID...');
        $chainId = config('bsc.chain_id.' . $env);
        $this->info("   ✓ Chain ID: {$chainId}");

        // 4. 测试 RPC 连接
        $this->info('[4/6] 测试 RPC 连接...');
        $rpcConnected = false;
        try {
            $gameState = BnbService::getGameState();

            if (isset($gameState['error'])) {
                $errorMsg = is_array($gameState['error'])
                    ? json_encode($gameState['error'])
                    : (string) $gameState['error'];
                $this->error("   ✗ RPC 连接失败: {$errorMsg}");
                $allPassed = false;
            } else {
                $this->info("   ✓ RPC 连接成功");
                $rpcConnected = true;
            }
        } catch (\Exception $e) {
            $this->error("   ✗ 调用异常: {$e->getMessage()}");
            $allPassed = false;
        }

        // 5. 如果 RPC 连通，显示游戏状态
        $this->info('[5/6] 获取游戏状态...');
        if ($rpcConnected) {
            try {
                $gameState = BnbService::getGameState();
                if (isset($gameState['error'])) {
                    $this->error("   ✗ 获取失败: {$gameState['error']}");
                    $allPassed = false;
                } else {
                    $this->info("   ✓ 游戏状态:");
                    $this->info("      - 游戏激活: " . ($gameState['gameActive'] ? '是' : '否'));
                    $this->info("      - 总奖池: {$gameState['totalPrizePool']} USDT");
                    $this->info("      - 随机奖池: {$gameState['randomPool']} USDT");
                    $this->info("      - 最后抢红包: " . $this->toString($gameState['lastGrabber']));
                }
            } catch (\Exception $e) {
                $this->error("   ✗ 获取异常: {$e->getMessage()}");
                $allPassed = false;
            }
        } else {
            $this->warn("   ⚠ 跳过 (RPC 未连通)");
            $this->warn("   💡 建议:");
            $this->warn("      1. 检查网络连接");
            $this->warn("      2. 使用正确的 BSC 测试网 RPC (bnbchain.org)");
            $this->warn("      3. 尝试使用本地节点或 VPN");
        }

        // 6. 测试读取用户信息
        $this->info('[6/6] 测试读取用户信息 (零地址)...');
        if ($rpcConnected) {
            try {
                $zeroAddress = '0x0000000000000000000000000000000000000000';
                $userInfo = BnbService::getUserInfo($zeroAddress);

                // 先检查是否有错误
                if (isset($userInfo['error']) && is_array($userInfo['error'])) {
                    $this->error("   ✗ 读取失败: " . json_encode($userInfo['error']));
                    $allPassed = false;
                } elseif (isset($userInfo['error']) && is_string($userInfo['error'])) {
                    $this->error("   ✗ 读取失败: {$userInfo['error']}");
                    $allPassed = false;
                } else {
                    // 直接打印数组以调试
                    $this->info("   ✓ 读取成功 (零地址用户):");
                    $inviter = $userInfo['inviter'] ?? 'null';
                    $totalGrabCount = $userInfo['totalGrabCount'] ?? '0';
                    $level = $userInfo['level'] ?? '0';
                    $pendingReward = $userInfo['pendingReward'] ?? '0';

                    // 转换对象为字符串
                    if (is_object($inviter) && method_exists($inviter, 'toString')) {
                        $inviter = $inviter->toString();
                    }
                    if (is_object($totalGrabCount) && method_exists($totalGrabCount, 'toString')) {
                        $totalGrabCount = $totalGrabCount->toString();
                    }
                    if (is_object($level) && method_exists($level, 'toString')) {
                        $level = $level->toString();
                    }
                    if (is_object($pendingReward) && method_exists($pendingReward, 'toString')) {
                        $pendingReward = $pendingReward->toString();
                    }

                    $this->info("      - 邀请人: {$inviter}");
                    $this->info("      - 抢红包次数: {$totalGrabCount}");
                    $this->info("      - 等级: {$level}");
                    $this->info("      - 待领取奖励: {$pendingReward} USDT");
                }
            } catch (\Exception $e) {
                $this->error("   ✗ 调用异常: {$e->getMessage()}");
                $allPassed = false;
            }
        } else {
            $this->warn("   ⚠ 跳过 (RPC 未连通)");
        }

        $this->info("\n========================================");
        if ($allPassed) {
            $this->info('✓ 所有检查通过！');
            return Command::SUCCESS;
        } else {
            $this->error('✗ 部分检查未通过');
            $this->error('请检查 .env 中的 BSC 配置');
            return Command::FAILURE;
        }
    }

    /**
     * 安全转换为字符串
     */
    private function toString(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }

    /**
     * 隐藏 API Key (只显示前 20 个字符)
     */
    private function maskApiKey(string $url): string
    {
        if (strlen($url) > 20) {
            return substr($url, 0, 20) . '...';
        }
        return $url;
    }
}
