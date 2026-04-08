<?php

namespace App\Console\Commands;

use App\Jobs\PmClaimOrderJob;
use App\Models\Pm\PmOrder;
use App\Services\Pm\PmOrderSettlementSyncService;
use Illuminate\Console\Command;

class PmSettleAndClaimOrderCommand extends Command
{
    protected $signature = 'pm:settle-and-claim
                            {--order-id=* : 指定订单ID，可多个}
                            {--from= : 起始订单ID}
                            {--to= : 结束订单ID}
                            {--no-claim : 只结算不兑奖}
                            {--force-claim : 强制重新兑奖（即使已兑奖）}';

    protected $description = '结算并兑换指定订单，打印详细记录';

    public function handle(PmOrderSettlementSyncService $syncService): int
    {
        $orderIds = $this->option('order-id');
        $from = $this->option('from');
        $to = $this->option('to');

        // 构建查询
        $query = PmOrder::query();

        if (!empty($orderIds)) {
            $query->whereIn('id', $orderIds);
        } elseif ($from || $to) {
            if ($from) {
                $query->where('id', '>=', $from);
            }
            if ($to) {
                $query->where('id', '<=', $to);
            }
        } else {
            $this->error('❌ 必须指定订单ID或范围');
            return 1;
        }

        $orders = $query->orderBy('id')->get();

        if ($orders->isEmpty()) {
            $this->warn('⚠️  未找到符合条件的订单');
            return 0;
        }

        $this->info("📋 找到 {$orders->count()} 个订单，开始处理...");
        $this->newLine();

        $stats = [
            'total' => 0,
            'settled' => 0,
            'claimed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($orders as $order) {
            $stats['total']++;
            $this->line(str_repeat('=', 100));
            $this->info("🔄 处理订单 #{$order->id}");
            $this->line(str_repeat('-', 100));

            // 显示订单基本信息
            $this->table(
                ['字段', '值'],
                [
                    ['订单ID', $order->id],
                    ['意图ID', $order->order_intent_id],
                    ['方向', $order->outcome ?? '-'],
                    ['数量', $order->filled_size ?? '-'],
                    ['成交额', sprintf('%.4f USDC', ($order->filled_usdc ?? 0) / 1000000)],
                    ['当前状态', $this->getStatusText($order->status)],
                    ['结算状态', $order->is_settled ? '✓ 已结算' : '✗ 未结算'],
                    ['兑奖状态', $this->getClaimStatusText($order->claim_status)],
                ]
            );

            // 步骤1: 结算
            $this->info('📊 步骤1: 同步结算状态');
            try {
                $this->line('  → 调用 syncService->sync()...');
                $result = $syncService->sync($order, false, false);

                $this->line('  → 同步结果: ' . json_encode([
                    'updated' => $result['updated'] ?? false,
                    'reason' => $result['reason'] ?? null,
                ]));

                if (isset($result['snapshot'])) {
                    $snapshot = $result['snapshot'];
                    $this->line('  → 结算快照:');
                    $this->line('    - is_settled: ' . ($snapshot['is_settled'] ? 'true' : 'false'));
                    $this->line('    - winning_outcome: ' . ($snapshot['winning_outcome'] ?? 'null'));
                    $this->line('    - settlement_source: ' . ($snapshot['settlement_source'] ?? 'null'));
                    $this->line('    - settled_at: ' . ($snapshot['settled_at'] ? $snapshot['settled_at']->toDateTimeString() : 'null'));
                    $this->line('    - is_win: ' . ($snapshot['is_win'] === null ? 'null' : ($snapshot['is_win'] ? 'true' : 'false')));
                    $this->line('    - pnl_usdc: ' . ($snapshot['pnl_usdc'] ?? 'null'));
                    $this->line('    - profit_usdc: ' . ($snapshot['profit_usdc'] ?? 'null'));

                    if (isset($result['snapshot']['settlement_payload'])) {
                        $payload = $result['snapshot']['settlement_payload'];
                        $this->line('  → settlement_payload:');
                        $this->line('    - condition_id: ' . ($payload['condition_id'] ?? 'null'));
                        $this->line('    - market存在: ' . (isset($payload['market']) ? 'yes' : 'no'));
                        $this->line('    - market_tokens存在: ' . (isset($payload['market_tokens']) ? 'yes' : 'no'));

                        if (isset($payload['market'])) {
                            $market = $payload['market'];
                            $this->line('    - market.closed: ' . ($market['closed'] ?? 'null'));
                            $this->line('    - market.closedTime: ' . ($market['closedTime'] ?? 'null'));
                            $this->line('    - market.endDate: ' . ($market['endDate'] ?? 'null'));
                            $this->line('    - market.umaResolutionStatus: ' . ($market['umaResolutionStatus'] ?? 'null'));
                        }

                        if (isset($payload['market_tokens'])) {
                            $this->line('    - market_tokens:');
                            foreach ($payload['market_tokens'] as $token) {
                                $winnerVal = $token['winner'] ?? 'null';
                                $winnerType = is_bool($winnerVal) ? 'bool' : (is_int($winnerVal) ? 'int' : (is_string($winnerVal) ? 'string' : gettype($winnerVal)));
                                $this->line('      * ' . ($token['outcome'] ?? '?') . ': winner=' . json_encode($winnerVal) . ' (type:' . $winnerType . '), price=' . ($token['price'] ?? 'null'));
                            }
                        }
                    }
                }

                $order->refresh();

                if ($order->is_settled) {
                    $stats['settled']++;
                    $this->info('✓ 结算成功');

                    // 显示结算结果
                    $this->table(
                        ['字段', '值'],
                        [
                            ['是否盈利', $order->is_win ? '✓ 盈利' : '✗ 亏损'],
                            ['盈亏(PNL)', sprintf('%+.4f USDC', ($order->pnl_usdc ?? 0) / 1000000)],
                            ['盈利金额', sprintf('%.4f USDC', ($order->profit_usdc ?? 0) / 1000000)],
                            ['结算时间', $order->settled_at?->format('Y-m-d H:i:s') ?? '-'],
                            ['结算来源', $order->settlement_source ?? '-'],
                        ]
                    );
                } else {
                    $this->warn('⚠️  订单尚未结算（市场可能未结束）');
                    $stats['skipped']++;
                    $this->newLine();
                    continue;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->error('✗ 结算失败');
                $this->error("错误类型: " . get_class($e));
                $this->error("错误信息: " . $e->getMessage());
                $this->error("错误位置: {$e->getFile()}:{$e->getLine()}");
                $this->newLine(2);
                continue;
            }

            // 步骤2: 兑奖
            if ($this->option('no-claim')) {
                $this->info('⏭️  跳过兑奖（--no-claim）');
                $this->newLine();
                continue;
            }

            if (!$order->is_win) {
                $this->info('⏭️  跳过兑奖（亏损订单）');
                $this->newLine();
                continue;
            }

            $forceClaim = $this->option('force-claim');
            if (!$forceClaim && $order->claim_status == PmOrder::CLAIM_STATUS_CONFIRMED) {
                $this->info('⏭️  跳过兑奖（已兑奖到账）');
                $this->newLine();
                continue;
            }

            $this->info('💰 步骤2: 触发兑奖');
            try {
                if ($forceClaim && $order->claim_status != PmOrder::CLAIM_STATUS_PENDING) {
                    $order->update([
                        'claim_status' => PmOrder::CLAIM_STATUS_PENDING,
                        'claim_tx_hash' => null,
                        'claim_last_error' => null,
                    ]);
                    $this->info('🔄 已重置兑奖状态');
                }

                \App\Jobs\PmAutoClaimOrderWinningsJob::dispatch($order->id);
                $this->info('✓ 兑奖任务已加入队列');

                // 等待兑奖完成
                $this->info('⏳ 等待兑奖完成...');
                $maxWait = 30; // 最多等待30秒
                $waited = 0;

                while ($waited < $maxWait) {
                    sleep(2);
                    $waited += 2;
                    $order->refresh();

                    if ($order->claim_status == PmOrder::CLAIM_STATUS_CONFIRMED) {
                        $stats['claimed']++;
                        $this->info('✓ 兑奖成功并确认到账');
                        $this->table(
                            ['字段', '值'],
                            [
                                ['兑奖状态', $this->getClaimStatusText($order->claim_status)],
                                ['交易哈希', $order->claim_tx_hash ?? '-'],
                                ['兑奖金额', sprintf('%.4f USDC', ($order->profit_usdc ?? 0) / 1000000)],
                            ]
                        );
                        break;
                    } elseif ($order->claim_status == PmOrder::CLAIM_STATUS_FAILED) {
                        $stats['failed']++;
                        $this->error('✗ 兑奖失败');
                        $this->error("失败原因: " . ($order->claim_last_error ?? '未知错误'));
                        if ($order->claim_tx_hash) {
                            $this->error("交易哈希: {$order->claim_tx_hash}");
                        }
                        break;
                    } elseif (in_array($order->claim_status, [PmOrder::CLAIM_STATUS_CLAIMING, PmOrder::CLAIM_STATUS_CLAIMED])) {
                        $this->line("  ⏳ 兑奖中... ({$waited}s)");
                    }
                }

                if ($waited >= $maxWait) {
                    $this->warn("⚠️  兑奖超时（{$maxWait}秒），当前状态: " . $this->getClaimStatusText($order->claim_status));
                    if ($order->claim_tx_hash) {
                        $this->info("交易哈希: {$order->claim_tx_hash}");
                    }
                }

            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->error('✗ 兑奖失败');
                $this->error("错误类型: " . get_class($e));
                $this->error("错误信息: " . $e->getMessage());
                $this->error("错误位置: {$e->getFile()}:{$e->getLine()}");
            }

            $this->newLine();
        }

        // 最终统计
        $this->line(str_repeat('=', 100));
        $this->info('📊 处理完成统计');
        $this->line(str_repeat('=', 100));
        $this->table(
            ['项目', '数量'],
            [
                ['总订单数', $stats['total']],
                ['成功结算', $stats['settled']],
                ['成功兑奖', $stats['claimed']],
                ['失败/错误', $stats['failed']],
                ['跳过处理', $stats['skipped']],
            ]
        );

        return 0;
    }

    private function getStatusText(int $status): string
    {
        return match ($status) {
            PmOrder::STATUS_NEW => '新建',
            PmOrder::STATUS_SUBMITTED => '已提交',
            PmOrder::STATUS_FILLED => '已成交',
            PmOrder::STATUS_PARTIAL => '部分成交',
            PmOrder::STATUS_CANCELED => '已取消',
            PmOrder::STATUS_REJECTED => '已拒绝',
            PmOrder::STATUS_ERROR => '错误',
            default => "未知({$status})",
        };
    }

    private function getClaimStatusText(int $status): string
    {
        return match ($status) {
            PmOrder::CLAIM_STATUS_NOT_NEEDED => '无需兑奖',
            PmOrder::CLAIM_STATUS_PENDING => '待兑奖',
            PmOrder::CLAIM_STATUS_CLAIMING => '兑奖中',
            PmOrder::CLAIM_STATUS_CLAIMED => '已兑奖',
            PmOrder::CLAIM_STATUS_FAILED => '兑奖失败',
            PmOrder::CLAIM_STATUS_SKIPPED => '已跳过',
            PmOrder::CLAIM_STATUS_CONFIRMED => '已确认到账',
            default => "未知({$status})",
        };
    }
}
