<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCustodyWallet;
use App\Services\Pm\PmDepositAutoConvertService;
use Illuminate\Console\Command;

class PmScanPossibleDepositsCommand extends Command
{
    protected $signature = 'pm:scan-possible-deposits {--once : 单次执行}';

    protected $description = '扫描可能充值的钱包，并处理 USDC 充值';

    public function handle(PmDepositAutoConvertService $service): int
    {
        $wallets = PmCustodyWallet::where('possible_deposit_status', 1)
            ->orderBy('deposit_scan_count')
            ->limit(60)
            ->get();

        if ($wallets->isEmpty()) {
            $this->info('没有待扫描的钱包');
            return self::SUCCESS;
        }

        foreach ($wallets as $wallet) {
            if (!$wallet->possible_deposit_at || $wallet->possible_deposit_at->copy()->addMinutes(15)->isPast()) {
                $this->resetPossibleDeposit($wallet);
                $this->line("wallet {$wallet->id}: 超时重置");
                continue;
            }

            try {
                $result = $service->processWallet($wallet);
                if (($result['handled'] ?? false) === true) {
                    $this->resetPossibleDeposit($wallet);
                    $this->line("wallet {$wallet->id}: 检测到 USDC 并完成处理");
                    continue;
                }
            } catch (\Throwable $e) {
                $this->error("wallet {$wallet->id}: 扫描失败 - {$e->getMessage()}");
            }

            $wallet->increment('deposit_scan_count');
        }

        return self::SUCCESS;
    }

    private function resetPossibleDeposit(PmCustodyWallet $wallet): void
    {
        $wallet->possible_deposit_status = 0;
        $wallet->possible_deposit_at = null;
        $wallet->deposit_scan_count = 0;
        $wallet->save();
    }
}
