<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyWallet;

class PmDepositAutoConvertService
{
    public function __construct(private readonly PolymarketTradingService $trading)
    {
    }

    public function processWallet(PmCustodyWallet $wallet): array
    {
        $usdcToken = trim((string) config('pm.collateral_token'));
        if ($usdcToken === '') {
            throw new \RuntimeException('PM_COLLATERAL_TOKEN 未配置');
        }

        $usdcRaw = $this->trading->getErc20BalanceRaw($wallet, $usdcToken);
        if (bccomp($usdcRaw, '0', 0) <= 0) {
            return [
                'detected' => false,
                'handled' => false,
                'token' => $usdcToken,
                'usdc_raw' => $usdcRaw,
                'reason' => 'no_usdc_balance',
            ];
        }

        return [
            'detected' => true,
            'handled' => true,
            'token' => $usdcToken,
            'usdc_raw' => $usdcRaw,
            'action' => 'already_collateral_token',
        ];
    }
}
