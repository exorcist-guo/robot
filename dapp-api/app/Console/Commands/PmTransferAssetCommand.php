<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCustodyWallet;
use App\Services\Pm\PmPrivateKeyResolver;
use App\Services\Pm\PolygonRpcService;
use EthTool\Credential;
use Illuminate\Console\Command;

class PmTransferAssetCommand extends Command
{
    protected $signature = 'pm:transfer-asset
        {--member-id= : pm_members.id}
        {--wallet-id= : pm_custody_wallets.id，优先级高于 member-id}
        {--token=usdc : usdc 或 usdc.e}
        {--to= : 收款地址}
        {--amount= : 转账金额，人类可读（如 10.5）}
        {--dry-run : 只预览不发送交易}';

    protected $description = '从托管钱包转出 USDC 或 USDC.e 到指定地址';

    public function handle(PmPrivateKeyResolver $resolver, PolygonRpcService $rpc): int
    {
        $wallet = $this->resolveWallet();
        if (!$wallet) {
            return self::FAILURE;
        }

        $tokenOption = strtolower(trim((string) $this->option('token')));
        $to = strtolower(trim((string) $this->option('to')));
        $amountInput = trim((string) $this->option('amount'));
        $dryRun = (bool) $this->option('dry-run');

        if (!in_array($tokenOption, ['usdc', 'usdc.e'], true)) {
            $this->error('token 仅支持 usdc 或 usdc.e');
            return self::FAILURE;
        }

        if (!preg_match('/^0x[a-f0-9]{40}$/', $to)) {
            $this->error('to 地址格式不正确');
            return self::FAILURE;
        }

        if (!preg_match('/^\d+(\.\d+)?$/', $amountInput) || bccomp($amountInput, '0', 6) <= 0) {
            $this->error('amount 必须为大于 0 的数字');
            return self::FAILURE;
        }

        $tokenAddress = $this->resolveTokenAddress($tokenOption);
        if ($tokenAddress === '') {
            $this->error("未配置 {$tokenOption} 的合约地址");
            return self::FAILURE;
        }

        $amountBaseUnits = $this->toBaseUnits($amountInput, 6);

        try {
            $privateKey = $resolver->resolve($wallet);
            $credential = Credential::fromKey(ltrim($privateKey, '0x'));
            $from = strtolower($credential->getAddress());
            $nonce = $rpc->getTransactionCount($from, 'pending');
            $gasPriceHex = $rpc->gasPrice();
            $gasLimit = 70000;
            $data = $this->encodeErc20Transfer($to, $amountBaseUnits);

            $raw = [
                'nonce' => $this->toRpcHex($nonce),
                'gasPrice' => $gasPriceHex,
                'gasLimit' => $this->toRpcHex($gasLimit),
                'to' => $tokenAddress,
                'value' => $this->toRpcHex(0),
                'data' => $data,
                'chainId' => (int) config('pm.chain_id', 137),
            ];

            $this->info('转账预览:');
            $this->line('  wallet_id: ' . $wallet->id);
            $this->line('  member_id: ' . $wallet->member_id);
            $this->line('  from: ' . $from);
            $this->line('  to: ' . $to);
            $this->line('  token: ' . $tokenOption . ' (' . $tokenAddress . ')');
            $this->line('  amount: ' . $amountInput);
            $this->line('  amount_base_units: ' . $amountBaseUnits);
            $this->line('  nonce: ' . $nonce);
            $this->line('  gas_price: ' . $gasPriceHex);
            $this->line('  gas_limit: ' . $gasLimit);
            $this->line('  data: ' . $data);

            if ($dryRun) {
                $this->info('dry-run 模式，不发送交易');
                return self::SUCCESS;
            }

            $signed = $credential->signTransaction($raw);
            $txHash = $rpc->sendRawTransaction($signed);

            $this->info('转账已提交');
            $this->line('tx_hash: ' . $txHash);
            $this->line('polygonscan: https://polygonscan.com/tx/' . $txHash);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('转账失败: ' . $e->getMessage());
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

        $wallet = PmCustodyWallet::where('member_id', (int) $memberId)
            ->where('status', PmCustodyWallet::STATUS_ENABLED)
            ->orderByDesc('id')
            ->first();

        if (!$wallet) {
            $this->error('member-id 对应钱包不存在');
            return null;
        }

        return $wallet;
    }

    private function resolveTokenAddress(string $token): string
    {
        if ($token === 'usdc') {
            return strtolower((string) config('pm.collateral_token'));
        }

        return strtolower((string) env('PM_USDC_E_TOKEN', ''));
    }

    private function toBaseUnits(string $amount, int $decimals): string
    {
        return bcmul($amount, bcpow('10', (string) $decimals, 0), 0);
    }

    private function encodeErc20Transfer(string $to, string $amountBaseUnits): string
    {
        return '0xa9059cbb'
            . $this->padHex(substr($to, 2))
            . $this->padHex($this->decToHex($amountBaseUnits));
    }

    private function padHex(string $hex, int $length = 64): string
    {
        return str_pad(ltrim($hex, '0x'), $length, '0', STR_PAD_LEFT);
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

    private function toRpcHex(int $value): string
    {
        return '0x' . dechex($value);
    }
}
