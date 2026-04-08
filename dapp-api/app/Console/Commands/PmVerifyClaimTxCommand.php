<?php

namespace App\Console\Commands;

use App\Models\Pm\PmOrder;
use App\Services\Pm\PolymarketClaimService;
use Illuminate\Console\Command;

class PmVerifyClaimTxCommand extends Command
{
    protected $signature = 'pm:verify-claim-tx
                            {--order-id= : 指定订单ID}
                            {--all-claimed : 验证所有兑奖中/已兑奖但未确认的订单}
                            {--dry-run : 只查看不更新}';

    protected $description = '验证兑奖交易是否已上链并更新状态（支持 CLAIMING 和 CLAIMED 状态）';

    public function handle(PolymarketClaimService $claimService): int
    {
        $orderId = $this->option('order-id');
        $allClaimed = $this->option('all-claimed');
        $dryRun = $this->option('dry-run');

        if ($orderId) {
            return $this->verifyOne((int) $orderId, $dryRun, $claimService);
        }

        if ($allClaimed) {
            return $this->verifyAll($dryRun, $claimService);
        }

        $this->error('请指定 --order-id 或 --all-claimed');
        return 1;
    }

    private function verifyOne(int $orderId, bool $dryRun, PolymarketClaimService $claimService): int
    {
        $order = PmOrder::find($orderId);
        if (!$order) {
            $this->error("订单 {$orderId} 不存在");
            return 1;
        }

        // 支持验证 CLAIMING 和 CLAIMED 两种状态
        if (!in_array($order->claim_status, [PmOrder::CLAIM_STATUS_CLAIMING, PmOrder::CLAIM_STATUS_CLAIMED])) {
            $this->warn("订单 {$orderId} 不需要验证 (当前状态: {$order->claim_status})");
            return 0;
        }

        $txHash = $order->claim_tx_hash;
        if (!$txHash) {
            $this->error("订单 {$orderId} 没有交易哈希");
            return 1;
        }

        $this->info("检查订单 {$orderId} 的交易: {$txHash}");

        $receipt = $claimService->getTransactionReceipt($txHash);

        if ($receipt === null) {
            $this->warn("  交易未找到或还在pending中");
            return 0;
        }

        $status = ($receipt['status'] ?? '0x0') === '0x1' ? 'SUCCESS' : 'FAILED';
        $blockNumber = isset($receipt['blockNumber']) ? hexdec($receipt['blockNumber']) : 'N/A';
        $gasUsed = isset($receipt['gasUsed']) ? hexdec($receipt['gasUsed']) : 'N/A';

        $this->info("  状态: {$status}");
        $this->info("  区块: {$blockNumber}");
        $this->info("  Gas: {$gasUsed}");

        if ($status === 'SUCCESS') {
            if ($dryRun) {
                $this->warn("  Dry-run模式，不更新状态");
            } else {
                $order->claim_status = PmOrder::CLAIM_STATUS_CONFIRMED;
                $order->claim_payload = array_merge(
                    is_array($order->claim_payload) ? $order->claim_payload : [],
                    [
                        'confirmed_at' => now()->toIso8601String(),
                        'block_number' => $blockNumber,
                        'gas_used' => $gasUsed,
                    ]
                );
                $order->save();
                $this->info("  ✓ 已更新为已确认状态");
            }
        } else {
            $this->error("  ✗ 交易执行失败");
            if (!$dryRun) {
                $order->claim_status = PmOrder::CLAIM_STATUS_FAILED;
                $order->claim_last_error = '链上交易执行失败';
                $order->save();
            }
        }

        return 0;
    }

    private function verifyAll(bool $dryRun, PolymarketClaimService $claimService): int
    {
        // 同时验证 CLAIMING 和 CLAIMED 两种状态的订单
        $orders = PmOrder::query()
            ->whereIn('claim_status', [PmOrder::CLAIM_STATUS_CLAIMING, PmOrder::CLAIM_STATUS_CLAIMED])
            ->whereNotNull('claim_tx_hash')
            ->where('claim_tx_hash', '!=', '')
            ->orderBy('id')
            ->get();

        $claimingCount = $orders->where('claim_status', PmOrder::CLAIM_STATUS_CLAIMING)->count();
        $claimedCount = $orders->where('claim_status', PmOrder::CLAIM_STATUS_CLAIMED)->count();

        $this->info("找到 {$orders->count()} 个待验证订单 (CLAIMING: {$claimingCount}, CLAIMED: {$claimedCount})");

        $confirmed = 0;
        $pending = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $receipt = $claimService->getTransactionReceipt($order->claim_tx_hash);

            if ($receipt === null) {
                $this->warn("订单 {$order->id}: 交易pending或未找到");
                $pending++;
                continue;
            }

            $status = ($receipt['status'] ?? '0x0') === '0x1' ? 'SUCCESS' : 'FAILED';
            $blockNumber = isset($receipt['blockNumber']) ? hexdec($receipt['blockNumber']) : 'N/A';

            if ($status === 'SUCCESS') {
                $this->info("订单 {$order->id}: ✓ 已确认 (区块 {$blockNumber})");
                $confirmed++;

                if (!$dryRun) {
                    $order->claim_status = PmOrder::CLAIM_STATUS_CONFIRMED;
                    $order->claim_payload = array_merge(
                        is_array($order->claim_payload) ? $order->claim_payload : [],
                        [
                            'confirmed_at' => now()->toIso8601String(),
                            'block_number' => $blockNumber,
                            'gas_used' => isset($receipt['gasUsed']) ? hexdec($receipt['gasUsed']) : null,
                        ]
                    );
                    $order->save();
                }
            } else {
                $this->error("订单 {$order->id}: ✗ 交易失败");
                $failed++;

                if (!$dryRun) {
                    $order->claim_status = PmOrder::CLAIM_STATUS_FAILED;
                    $order->claim_last_error = '链上交易执行失败';
                    $order->save();
                }
            }

            usleep(100000); // 0.1秒延迟
        }

        $this->info("完成: 已确认={$confirmed}, pending={$pending}, 失败={$failed}");
        return 0;
    }
}
