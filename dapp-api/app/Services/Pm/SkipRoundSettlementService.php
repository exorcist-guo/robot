<?php

namespace App\Services\Pm;

use App\Models\Pm\PmSkipRoundOrder;
use App\Models\Pm\PmSkipRoundStrategy;
use App\Models\Pm\PmTailSweepRoundOpenPrice;
use Illuminate\Support\Carbon;

class SkipRoundSettlementService
{
    public function __construct(private readonly SkipRoundLineStateService $lineStateService)
    {
    }

    /**
     * 结算指定策略下所有“已成交但未结算”的订单。
     *
     * 结算口径：
     * 1. 只有目标轮结束后才允许结算；
     * 2. 从订单快照里拿 price_to_beat；
     * 3. 用目标轮的 round_open_price 和 price_to_beat 比较，判断真实方向；
     * 4. 真实方向与 predicted_side 一致则记为 win，否则记为 lose；
     * 5. 结算完成后推进该订单所属 A/B 线的资金状态。
     *
     * @param array<string,mixed> $config
     */
    public function settleStrategy(PmSkipRoundStrategy $strategy, array $config): int
    {
        $count = 0;

        // 只挑“已经完成下单动作、但还没有正式结算”的订单。
        // 这里包含三类状态：
        // - filled：限价单已完全成交
        // - partially_filled：限价单部分成交
        // - market_buy_submitted：撤单后补市价单已经发出
        $orders = PmSkipRoundOrder::query()
            ->where('strategy_id', $strategy->id)
            ->whereIn('status', [
                PmSkipRoundOrder::STATUS_FILLED,
                PmSkipRoundOrder::STATUS_PARTIALLY_FILLED,
                PmSkipRoundOrder::STATUS_MARKET_BUY_SUBMITTED,
            ])
            ->whereNull('settled_at')
            ->get();

        foreach ($orders as $order) {
            // target_round_key 表示这笔单真正要赌的那一轮结束时间。
            // 只有当现在已经晚于这轮结束时间，才允许开始结算。
            $targetRoundKey = (int) $order->target_round_key;
            if ($targetRoundKey <= 0 || time() < $targetRoundKey) {
                continue;
            }

            // 下单时会把解析出来的 market 原始数据写进 snapshot。
            // 结算阶段从这里取出 price_to_beat，作为 up/down 胜负判断基准。
            $marketPayload = is_array($order->snapshot['market'] ?? null) ? $order->snapshot['market'] : [];
            $priceToBeat = (string) ($marketPayload['price_to_beat'] ?? '');
            if ($priceToBeat === '') {
                // 连 price_to_beat 都没有，说明这笔单的市场上下文不完整，无法结算。
                $order->status = PmSkipRoundOrder::STATUS_FAILED;
                $order->fail_reason = 'missing_price_to_beat';
                $order->save();
                continue;
            }

            // 读取目标轮在 round_open 表中的开盘价。
            // 当前实现把“目标轮开盘价”和“price_to_beat”的比较结果，作为真实 outcome。
            $roundOpen = PmTailSweepRoundOpenPrice::query()
                ->where('symbol', (string) ($config['symbol'] ?? 'btc/usd'))
                ->where('round_start_at', Carbon::createFromTimestamp($targetRoundKey))
                ->value('round_open_price');
            if (!is_string($roundOpen) || $roundOpen === '') {
                // 缺少目标轮开盘价时，也无法判断输赢，只能记失败。
                $order->status = PmSkipRoundOrder::STATUS_FAILED;
                $order->fail_reason = 'missing_target_round_open_price';
                $order->save();
                continue;
            }

            // 真实方向判定：
            // - 目标轮开盘价 > price_to_beat => up
            // - 否则 => down
            $actual = bccomp($roundOpen, $priceToBeat, 8) === 1 ? 'up' : 'down';

            // 如果预测方向和真实方向一致，则记为 win，否则记为 lose。
            $result = $order->predicted_side === $actual ? 'win' : 'lose';

            // 有效下注金额优先取真实成交金额：
            // - matched_notional：限价单已成交部分
            // - market_buy_notional：撤单后补市价成交部分
            // 如果两者都没有，则回退到原始 bet_amount。
            $effectiveBet = bcadd((string) $order->matched_notional, (string) $order->market_buy_notional, 8);
            if (bccomp($effectiveBet, '0', 8) !== 1) {
                $effectiveBet = (string) $order->bet_amount;
            }

            // 当前实现的 pnl 口径比较直接：
            // - 赢：盈利额记为正的下注金额
            // - 输：亏损额记为负的下注金额
            $pnl = $result === 'win' ? $effectiveBet : bcmul($effectiveBet, '-1', 8);

            // 回写订单结算结果。
            $order->result = $result;
            $order->pnl_amount = $pnl;
            $order->status = PmSkipRoundOrder::STATUS_SETTLED;
            $order->settled_at = Carbon::now();
            $order->snapshot = array_merge($order->snapshot ?? [], [
                'settlement' => [
                    'price_to_beat' => $priceToBeat,
                    'target_round_open_price' => $roundOpen,
                    'actual_side' => $actual,
                    'effective_bet' => $effectiveBet,
                ],
            ]);
            $order->save();

            // 结算完订单后，推进所属 A/B 线状态：
            // - 统计 win/lose 次数
            // - 更新连亏次数
            // - 计算下一次下注金额
            $this->lineStateService->settle($order->line, $strategy, $config, (string) $order->target_round_key, $result, $order->id);
            $count++;
        }

        return $count;
    }
}
