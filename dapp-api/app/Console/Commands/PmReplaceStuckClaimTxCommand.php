<?php

namespace App\Console\Commands;

use App\Models\Pm\PmOrder;
use App\Services\Pm\PolymarketClaimService;
use App\Services\Pm\PmPrivateKeyResolver;
use EthTool\Credential;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

class PmReplaceStuckClaimTxCommand extends Command
{
    protected $signature = 'pm:replace-stuck-claim-tx
                            {--order-id= : 指定订单ID}
                            {--from-order-id= : 从指定订单ID开始批量处理}
                            {--gas-multiplier=1.2 : Gas Price倍数}
                            {--dry-run : 只查看不执行}';

    protected $description = '替换卡在mempool中的兑奖交易';

    public function handle(
        PolymarketClaimService $claimService,
        PmPrivateKeyResolver $privateKeyResolver
    ): int {
        $orderId = $this->option('order-id');
        $fromOrderId = $this->option('from-order-id');
        $gasMultiplier = (float) $this->option('gas-multiplier');
        $dryRun = $this->option('dry-run');

        if ($orderId) {
            return $this->replaceOne((int) $orderId, $gasMultiplier, $dryRun, $claimService, $privateKeyResolver);
        }

        if ($fromOrderId) {
            return $this->replaceBatch((int) $fromOrderId, $gasMultiplier, $dryRun, $claimService, $privateKeyResolver);
        }

        $this->error('请指定 --order-id 或 --from-order-id');
        return 1;
    }

    private function replaceOne(
        int $orderId,
        float $gasMultiplier,
        bool $dryRun,
        PolymarketClaimService $claimService,
        PmPrivateKeyResolver $privateKeyResolver
    ): int {
        $order = PmOrder::with('intent.copyTask.member.custodyWallet')->find($orderId);
        if (!$order) {
            $this->error("订单 {$orderId} 不存在");
            return 1;
        }

        if ($order->claim_status !== PmOrder::CLAIM_STATUS_CLAIMED) {
            $this->error("订单 {$orderId} 不是已兑奖状态");
            return 1;
        }

        $oldTxHash = $order->claim_tx_hash;
        if (!$oldTxHash) {
            $this->error("订单 {$orderId} 没有交易哈希");
            return 1;
        }

        $rpcUrl = trim((string) config('pm.polygon_rpc_url'));
        if ($rpcUrl === '') {
            $this->error('PM_POLYGON_RPC_URL 未配置');
            return 1;
        }

        // 检查旧交易状态
        $client = new Client(['timeout' => 10]);
        $oldTx = $this->getTransaction($client, $rpcUrl, $oldTxHash);

        if (!$oldTx) {
            $this->error("交易 {$oldTxHash} 不存在");
            return 1;
        }

        if ($oldTx['blockHash'] !== null) {
            $this->info("订单 {$orderId} 的交易已上链，无需替换");
            return 0;
        }

        $oldNonce = hexdec(substr($oldTx['nonce'], 2));
        $oldGasPrice = hexdec(substr($oldTx['gasPrice'], 2));
        $newGasPrice = (int) ($oldGasPrice * $gasMultiplier);

        $this->info("订单 {$orderId}:");
        $this->info("  旧交易: {$oldTxHash}");
        $this->info("  Nonce: {$oldNonce}");
        $this->info("  旧Gas Price: {$oldGasPrice} wei");
        $this->info("  新Gas Price: {$newGasPrice} wei (x{$gasMultiplier})");

        if ($dryRun) {
            $this->warn('Dry-run模式，不执行替换');
            return 0;
        }

        // 重新构建交易
        $wallet = $order->intent?->member?->custodyWallet;
        if (!$wallet) {
            $this->error('订单缺少托管钱包');
            return 1;
        }

        $privateKey = $privateKeyResolver->resolve($wallet);
        $credential = Credential::fromKey(ltrim($privateKey, '0x'));

        $plan = $claimService->buildClaimPlan($order);
        if (($plan['ready'] ?? false) !== true) {
            $this->error('兑奖参数不完整');
            return 1;
        }

        $chainId = (int) config('pm.chain_id', 137);
        $gasLimit = 220000;

        $raw = [
            'nonce' => '0x' . dechex($oldNonce), // 使用相同的nonce
            'gasPrice' => '0x' . dechex($newGasPrice),
            'gasLimit' => '0x' . dechex($gasLimit),
            'to' => (string) $plan['contract'],
            'value' => '0x0',
            'data' => (string) $plan['calldata'],
            'chainId' => $chainId,
        ];

        $signed = $credential->signTransaction($raw);

        try {
            $newTxHash = $this->sendRawTransaction($client, $rpcUrl, $signed);

            $order->claim_tx_hash = $newTxHash;
            $order->claim_payload = array_merge(
                is_array($order->claim_payload) ? $order->claim_payload : [],
                [
                    'replaced' => true,
                    'old_tx_hash' => $oldTxHash,
                    'new_tx_hash' => $newTxHash,
                    'old_gas_price' => '0x' . dechex($oldGasPrice),
                    'new_gas_price' => '0x' . dechex($newGasPrice),
                    'replaced_at' => now()->toIso8601String(),
                ]
            );
            $order->save();

            $this->info("✓ 替换成功，新交易: {$newTxHash}");
            return 0;
        } catch (\Exception $e) {
            $this->error("替换失败: {$e->getMessage()}");
            return 1;
        }
    }

    private function replaceBatch(
        int $fromOrderId,
        float $gasMultiplier,
        bool $dryRun,
        PolymarketClaimService $claimService,
        PmPrivateKeyResolver $privateKeyResolver
    ): int {
        $orders = PmOrder::query()
            ->where('id', '>=', $fromOrderId)
            ->where('claim_status', PmOrder::CLAIM_STATUS_CLAIMED)
            ->whereNotNull('claim_tx_hash')
            ->orderBy('id')
            ->get();

        $this->info("找到 {$orders->count()} 个订单");

        $replaced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $result = $this->replaceOne(
                $order->id,
                $gasMultiplier,
                $dryRun,
                $claimService,
                $privateKeyResolver
            );

            if ($result === 0) {
                $replaced++;
            } elseif ($result === 1) {
                $failed++;
            } else {
                $skipped++;
            }

            usleep(500000); // 0.5秒延迟
        }

        $this->info("完成: 替换={$replaced}, 跳过={$skipped}, 失败={$failed}");
        return $failed > 0 ? 1 : 0;
    }

    private function getTransaction(Client $client, string $rpcUrl, string $txHash): ?array
    {
        $response = $client->post($rpcUrl, [
            'json' => [
                'jsonrpc' => '2.0',
                'method' => 'eth_getTransactionByHash',
                'params' => [$txHash],
                'id' => 1,
            ],
        ]);

        $json = json_decode($response->getBody()->getContents(), true);
        return $json['result'] ?? null;
    }

    private function sendRawTransaction(Client $client, string $rpcUrl, string $signedTx): string
    {
        $response = $client->post($rpcUrl, [
            'json' => [
                'jsonrpc' => '2.0',
                'method' => 'eth_sendRawTransaction',
                'params' => [$signedTx],
                'id' => 1,
            ],
        ]);

        $json = json_decode($response->getBody()->getContents(), true);

        if (!empty($json['error'])) {
            $message = is_array($json['error'])
                ? ($json['error']['message'] ?? 'RPC调用失败')
                : (string) $json['error'];
            throw new \RuntimeException($message);
        }

        return $json['result'] ?? '';
    }
}
