<?php

namespace App\Console\Commands;

use App\Models\Pm\PmMember;
use App\Services\Pm\PolymarketTradingService;
use Illuminate\Console\Command;

class PmApproveWalletCommand extends Command
{
    protected $signature = 'pm:approve-wallet {member_id=1 : pm_members.id} {side=BUY : BUY 或 SELL} {token_id? : SELL 时必填 token_id}';

    protected $description = '测试托管钱包授权链路（会真实发起 approve 交易）';

    public function handle(PolymarketTradingService $trading): int
    {
        $memberId = (int) $this->argument('member_id');
        $side = strtoupper((string) $this->argument('side'));
        $tokenId = $this->argument('token_id');
        if (!in_array($side, [PolymarketTradingService::SIDE_BUY, PolymarketTradingService::SIDE_SELL], true)) {
            $this->error('side 只支持 BUY 或 SELL');
            return self::FAILURE;
        }
        if ($side === PolymarketTradingService::SIDE_SELL && (!is_string($tokenId) || trim($tokenId) === '')) {
            $this->error('SELL 授权必须传 token_id');
            return self::FAILURE;
        }
        $member = PmMember::with('custodyWallet.apiCredentials')->find($memberId);

        if (!$member) {
            $this->error('member 不存在');
            return self::FAILURE;
        }

        $wallet = $member->custodyWallet;
        if (!$wallet) {
            $this->error('未导入托管钱包');
            return self::FAILURE;
        }

        $this->info('成员: ' . $member->address . ' (id=' . $member->id . ')');
        $this->info('signer: ' . $wallet->signer_address);
        $this->info('funder: ' . ($wallet->funder_address ?: '-'));

        try {
            $status = $side === PolymarketTradingService::SIDE_SELL
                ? $trading->getConditionalAllowanceStatus($wallet, (string) $tokenId)
                : $trading->getAllowanceStatus($wallet);
            $this->info('授权前状态:');
            $this->line(json_encode($status, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->error('查询授权前状态失败: ' . $e->getMessage());
            return self::FAILURE;
        }

        try {
            $result = $trading->approveForSide($wallet, $side, $tokenId ? (string) $tokenId : null);
            $this->info('授权结果:');
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->error('授权失败: ' . $e->getMessage());
            return self::FAILURE;
        }

        try {
            $status = $side === PolymarketTradingService::SIDE_SELL
                ? $trading->getConditionalAllowanceStatus($wallet, (string) $tokenId)
                : $trading->getAllowanceStatus($wallet);
            $this->info('授权后状态:');
            $this->line(json_encode($status, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->warn('授权后状态查询失败: ' . $e->getMessage());
        }

        return self::SUCCESS;
    }
}
