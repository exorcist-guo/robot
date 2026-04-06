<?php

namespace App\Console\Commands;

use App\Models\Pm\PmLeader;
use App\Models\Pm\PmMember;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketTradingService;
use Illuminate\Console\Command;

class PmValidateSetupCommand extends Command
{
    protected $signature = 'pm:validate-setup {member_ref? : pm_members.id 或 钱包地址} {leader_address?} {sell_token_id? : 可选，检查 SELL 授权时使用}';

    protected $description = '只读校验当前 Polymarket 联调准备状态（不下单）';

    public function handle(GammaClient $gamma): int
    {
        $memberRef = $this->argument('member_ref');
        $leaderAddress = $this->argument('leader_address');
        $sellTokenId = $this->argument('sell_token_id');

        if (!$memberRef) {
            $this->warn('请传 member_ref，例如: php artisan pm:validate-setup 1 0x... 或 php artisan pm:validate-setup 0x你的钱包地址 0xleader地址');
            return self::FAILURE;
        }

        $memberQuery = PmMember::with('custodyWallet.apiCredentials');
        if (is_string($memberRef) && preg_match('/^0x[a-fA-F0-9]{40}$/', $memberRef)) {
            $member = $memberQuery->where('address', strtolower($memberRef))->first();
        } elseif (is_numeric($memberRef)) {
            $member = $memberQuery->find((int) $memberRef);
        } else {
            $member = null;
        }

        if (!$member) {
            $this->error('member 不存在。请使用 pm_members.id 或钱包地址');
            return self::FAILURE;
        }

        $this->info('成员: ' . $member->address . ' (id=' . $member->id . ')');

        $wallet = $member->custodyWallet;
        if (!$wallet) {
            $this->error('PM 托管钱包不存在，请先让该用户重新登录');
            return self::FAILURE;
        }

        $this->info('login address: ' . ($wallet->address ?: '-'));
        $this->info('signer: ' . $wallet->signer_address);
        $this->info('funder: ' . ($wallet->funder_address ?: '-'));

        if (!config('pm.custody_key')) {
            $this->error('未配置 PM_CUSTODY_KEY（用于加密 Polymarket API 凭证）');
            $this->line('请先在 .env 中添加一行，例如：');
            $this->line('PM_CUSTODY_KEY=base64:这里放32字节随机密钥');
            $this->line('可用以下命令生成： php -r "echo \"base64:\".base64_encode(random_bytes(32)).PHP_EOL;"');
            return self::FAILURE;
        }

        /** @var PolymarketTradingService $trading */
        $trading = app(PolymarketTradingService::class);

        try {
            $creds = $wallet->apiCredentials ?: $trading->ensureApiCredentials($wallet);
            $this->info('API credentials 已就绪');
            $this->line('api_key_ciphertext length: ' . strlen((string) $creds->api_key_ciphertext));
        } catch (\Throwable $e) {
            $this->error('API credentials 初始化失败: ' . $e->getMessage());
            return self::FAILURE;
        }

        try {
            $buyStatus = $trading->getAllowanceStatus($wallet);
            $this->info('BUY balance-allowance 获取成功');
            $this->line(json_encode($buyStatus, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $this->error('BUY balance-allowance 获取失败: ' . $e->getMessage());
        }

        if (is_string($sellTokenId) && trim($sellTokenId) !== '') {
            try {
                $sellStatus = $trading->getConditionalAllowanceStatus($wallet, trim($sellTokenId));
                $this->info('SELL conditional allowance 获取成功');
                $this->line(json_encode($sellStatus, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                $this->error('SELL conditional allowance 获取失败: ' . $e->getMessage());
            }
        }

        if ($leaderAddress) {
            try {
                $profile = $gamma->getPublicProfile(strtolower($leaderAddress));
                $this->info('leader public-profile 获取成功');
                $this->line(json_encode($profile, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $e) {
                $this->error('leader public-profile 获取失败: ' . $e->getMessage());
            }
        }

        $this->comment('校验完成（未下真实单）');
        return self::SUCCESS;
    }
}
