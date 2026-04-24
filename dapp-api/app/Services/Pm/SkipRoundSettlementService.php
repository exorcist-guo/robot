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
     * @param array<string,mixed> $config
     */
    public function settleStrategy(PmSkipRoundStrategy $strategy, array $config): int
    {
        $count = 0;
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
            $targetRoundKey = (int) $order->target_round_key;
            if ($targetRoundKey <= 0 || time() < $targetRoundKey) {
                continue;
            }

            $marketPayload = is_array($order->snapshot['market'] ?? null) ? $order->snapshot['market'] : [];
            $priceToBeat = (string) ($marketPayload['price_to_beat'] ?? '');
            if ($priceToBeat === '') {
                $order->status = PmSkipRoundOrder::STATUS_FAILED;
                $order->fail_reason = 'missing_price_to_beat';
                $order->save();
                continue;
            }

            $roundOpen = PmTailSweepRoundOpenPrice::query()
                ->where('symbol', (string) ($config['symbol'] ?? 'btc/usd'))
                ->where('round_start_at', Carbon::createFromTimestamp($targetRoundKey))
                ->value('round_open_price');
            if (!is_string($roundOpen) || $roundOpen === '') {
                $order->status = PmSkipRoundOrder::STATUS_FAILED;
                $order->fail_reason = 'missing_target_round_open_price';
                $order->save();
                continue;
            }

            $actual = bccomp($roundOpen, $priceToBeat, 8) === 1 ? 'up' : 'down';
            $result = $order->predicted_side === $actual ? 'win' : 'lose';
            $effectiveBet = bcadd((string) $order->matched_notional, (string) $order->market_buy_notional, 8);
            if (bccomp($effectiveBet, '0', 8) !== 1) {
                $effectiveBet = (string) $order->bet_amount;
            }
            $pnl = $result === 'win' ? $effectiveBet : bcmul($effectiveBet, '-1', 8);

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

            $this->lineStateService->settle($order->line, $strategy, $config, (string) $order->target_round_key, $result, $order->id);
            $count++;
        }

        return $count;
    }
}
