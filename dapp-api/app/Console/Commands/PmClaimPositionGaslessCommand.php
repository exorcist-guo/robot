<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCustodyWallet;
use App\Services\Pm\PolymarketClaimOrchestrator;
use Illuminate\Console\Command;

class PmClaimPositionGaslessCommand extends Command
{
    protected $signature = 'pm:claim-position-gasless
                            {address? : 资金方地址或托管钱包地址}
                            {--condition-id= : 指定要领取的 Condition ID}
                            {--dry-run : 只查询不执行领取}
                            {--include-losing : 包含已输但链上仍可 redeem 的持仓}
                            {--scan-all : 扫描所有钱包并自动领取}
                            {--min-age=3600 : 最小订单年龄（秒），默认 3600 秒（1 小时）}
                            {--gasless-only : 仅尝试 gasless，不回退链上}
                            {--onchain-only : 跳过 gasless，直接走链上}
                            {--fallback=1 : gasless 失败是否回退链上，默认 1}
                            {--timeout=30 : gasless 请求超时时间（秒）}';

    protected $description = '优先通过 Polymarket gasless relayer 领取奖励，失败时回退链上 redeem';

    public function handle(PolymarketClaimOrchestrator $orchestrator): int
    {
        if ($this->option('scan-all')) {
            return $this->scanAllWallets($orchestrator);
        }

        $address = strtolower(trim((string) $this->argument('address')));
        if ($address === '') {
            $this->error('❌ 请提供地址参数，或使用 --scan-all 扫描所有钱包');
            return 1;
        }

        $wallet = PmCustodyWallet::where('funder_address', $address)
            ->orWhere('signer_address', $address)
            ->orWhere('address', $address)
            ->first();

        if (!$wallet) {
            $this->error("❌ 未找到地址 {$address} 对应的托管钱包");
            return 1;
        }

        return $this->processWallet($wallet, $orchestrator, true);
    }

    private function processWallet(PmCustodyWallet $wallet, PolymarketClaimOrchestrator $orchestrator, bool $verbose = false): int
    {
        $conditionId = strtolower(trim((string) $this->option('condition-id')));
        $dryRun = (bool) $this->option('dry-run');
        $includeLosing = (bool) $this->option('include-losing');
        $gaslessOnly = (bool) $this->option('gasless-only');
        $onchainOnly = (bool) $this->option('onchain-only');
        $fallback = filter_var($this->option('fallback'), FILTER_VALIDATE_BOOL);
        $timeout = max(1, (int) $this->option('timeout'));

        if ($verbose) {
            $this->line("托管钱包地址: {$wallet->address}");
            $this->line("Signer 地址: {$wallet->signer_address}");
            $this->line("Funder 地址: {$wallet->funder_address}");
            $this->newLine();
        }

        $positions = $this->fetchPositions($wallet->signer_address);
        if ($positions === []) {
            if ($verbose) {
                $this->warn('⚠️  未找到任何持仓');
            }
            return 0;
        }

        $claimablePositions = [];
        foreach ($positions as $position) {
            $currentValue = (float) ($position['currentValue'] ?? 0);
            $isRedeemable = (bool) ($position['redeemable'] ?? false);
            $canRedeemLosing = $includeLosing && $currentValue <= 0 && $isRedeemable;
            if (($currentValue > 0 || $canRedeemLosing) && $isRedeemable) {
                if ($conditionId !== '' && strtolower((string) ($position['conditionId'] ?? '')) !== $conditionId) {
                    continue;
                }
                $claimablePositions[] = $position;
            }
        }

        if ($claimablePositions === []) {
            if ($verbose) {
                $this->warn('⚠️  没有可领取的持仓');
            }
            return 0;
        }

        $groupedPositions = [];
        foreach ($claimablePositions as $position) {
            $groupKey = strtolower((string) ($position['conditionId'] ?? ''));
            if ($groupKey === '') {
                $groupKey = 'position_' . count($groupedPositions);
            }
            if (isset($groupedPositions[$groupKey])) {
                continue;
            }

            $positionsGroup = array_values(array_filter($positions, function (array $candidate) use ($groupKey, $includeLosing) {
                $currentValue = (float) ($candidate['currentValue'] ?? 0);
                $isRedeemable = (bool) ($candidate['redeemable'] ?? false);
                $canRedeemLosing = $includeLosing && $currentValue <= 0 && $isRedeemable;

                return strtolower((string) ($candidate['conditionId'] ?? '')) === $groupKey
                    && $isRedeemable
                    && ($currentValue > 0 || $canRedeemLosing);
            }));

            $groupedPositions[$groupKey] = $positionsGroup !== [] ? $positionsGroup : [$position];
        }

        $success = 0;
        $failed = 0;

        foreach (array_values($groupedPositions) as $index => $positionsGroup) {
            $primary = $positionsGroup[0] ?? [];
            $this->info('[' . ($index + 1) . '/' . count($groupedPositions) . '] ' . ($primary['title'] ?? 'Unknown'));
            $result = $orchestrator->claimPositions(
                $wallet,
                $positionsGroup,
                $dryRun,
                $gaslessOnly,
                $onchainOnly,
                $fallback,
                $timeout,
            );

            $channel = (string) ($result['channel'] ?? 'unknown');
            $fallbackUsed = (bool) ($result['fallback_used'] ?? false);
            if (($result['success'] ?? false) === true) {
                $success++;
                $this->info('✅ 成功，通道: ' . $channel . ($fallbackUsed ? ' (fallback)' : ''));
                if (!empty($result['tx_hash'])) {
                    $this->line('TX: ' . $result['tx_hash']);
                }
                if (!empty($result['transaction_id'])) {
                    $this->line('Relayer Transaction ID: ' . $result['transaction_id']);
                }
            } else {
                $failed++;
                $this->error('❌ 失败，通道: ' . $channel . ($fallbackUsed ? ' (fallback)' : ''));
                $this->line('原因: ' . (string) ($result['error'] ?? $result['error_message'] ?? $result['reason'] ?? 'unknown'));
                if (!empty($result['tx_hash'])) {
                    $this->line('TX: ' . $result['tx_hash']);
                }
            }
            $this->newLine();
        }

        return $failed > 0 ? 1 : 0;
    }

    private function scanAllWallets(PolymarketClaimOrchestrator $orchestrator): int
    {
        $wallets = PmCustodyWallet::whereNotNull('en_private_key')
            ->where('en_private_key', '!=', '')
            ->get();

        $failed = 0;
        foreach ($wallets as $index => $wallet) {
            $this->line(str_repeat('=', 100));
            $this->info('[' . ($index + 1) . '/' . $wallets->count() . '] 钱包: ' . $wallet->signer_address);
            $result = $this->processWallet($wallet, $orchestrator, false);
            if ($result !== 0) {
                $failed++;
            }
        }

        return $failed > 0 ? 1 : 0;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchPositions(string $signerAddress): array
    {
        $json = @file_get_contents('https://data-api.polymarket.com/positions?user=' . strtolower($signerAddress));
        if (!$json) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
