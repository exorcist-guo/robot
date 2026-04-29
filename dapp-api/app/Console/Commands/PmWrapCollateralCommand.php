<?php

namespace App\Console\Commands;

use App\Models\Pm\PmMember;
use App\Models\Pm\PmCustodyWallet;
use App\Services\Pm\PolymarketTradingService;
use Illuminate\Console\Command;

class PmWrapCollateralCommand extends Command
{
    protected $signature = 'pm:wrap-collateral
        {--member-id= : pm_members.id}
        {--wallet-id= : pm_custody_wallets.id，优先级高于 member-id}';

    protected $description = '自动识别 USDC.e 余额并 wrap 成 pUSD（会真实发链上交易）';

    public function handle(PolymarketTradingService $trading): int
    {
        $wallet = $this->resolveWallet();
        if (!$wallet) {
            return self::FAILURE;
        }

        $this->info('wallet_id: ' . $wallet->id);
        $this->info('member_id: ' . $wallet->member_id);
        $this->info('login address: ' . ($wallet->address ?: '-'));
        $this->info('signer: ' . $wallet->signer_address);
        $this->info('funder: ' . ($wallet->funder_address ?: '-'));

        try {
            $result = $trading->wrapCollateralToPusd($wallet);
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('wrap 失败: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function resolveWallet(): ?PmCustodyWallet
    {
        $walletId = $this->option('wallet-id');
        $memberId = $this->option('member-id');

        if ($walletId !== null && $walletId !== '') {
            $wallet = PmCustodyWallet::find((int) $walletId);
            if (!$wallet) {
                $this->error('wallet-id 对应钱包不存在');
                return null;
            }
            return $wallet;
        }

        if ($memberId === null || $memberId === '') {
            $this->error('请提供 --wallet-id 或 --member-id');
            return null;
        }

        $member = PmMember::find((int) $memberId);
        if (!$member) {
            $this->error('member 不存在');
            return null;
        }

        $wallet = PmCustodyWallet::where('member_id', $member->id)
            ->where('status', PmCustodyWallet::STATUS_ENABLED)
            ->orderByDesc('id')
            ->first();

        if (!$wallet) {
            $this->error('member-id 对应钱包不存在');
            return null;
        }

        return $wallet;
    }
}
