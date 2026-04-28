<?php

namespace App\Services\Pm;

use App\Models\Pm\PmOrder;
use App\Models\Pm\PmOrderIntent;
use App\Models\Pm\PmPurchaseTracking;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PurchaseTrackingService
{
    public function recordBuyOrder(PmOrder $order): void
    {
        $order->loadMissing('intent.copyTask');
        $intent = $order->intent;
        if (!$intent || strtoupper((string) $intent->side) !== PolymarketTradingService::SIDE_BUY) {
            return;
        }

        $filledSize = $this->resolveFilledSize($order);
        if ($filledSize === null || bccomp($filledSize, '0', 8) <= 0) {
            return;
        }

        $avgPrice = $order->avg_price ?: (string) ($order->request_payload['normalized_price'] ?? $order->order_price ?? '');
        $marketId = (string) ($order->request_payload['market_id'] ?? $order->request_payload['request']['input']['market_id'] ?? '');

        PmPurchaseTracking::updateOrCreate(
            ['order_id' => $order->id],
            [
                'member_id' => (int) $intent->member_id,
                'copy_task_id' => (int) $intent->copy_task_id,
                'leader_trade_id' => $intent->leader_trade_id ? (int) $intent->leader_trade_id : null,
                'order_intent_id' => (int) $intent->id,
                'market_id' => $marketId !== '' ? $marketId : null,
                'token_id' => (string) $intent->token_id,
                'bought_size' => $filledSize,
                'remaining_size' => $filledSize,
                'avg_price' => $avgPrice !== '' ? $avgPrice : null,
                'source_type' => (string) ($intent->risk_snapshot['mode'] ?? 'leader_copy'),
                'opened_at' => $order->submitted_at ?: now(),
                'meta' => [
                    'order_status' => $order->status,
                    'filled_usdc' => (int) $order->filled_usdc,
                ],
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function allocateForSell(PmOrder $order, string $sellSize): array
    {
        $order->loadMissing('intent');
        $intent = $order->intent;
        if (!$intent || trim($sellSize) === '' || bccomp($sellSize, '0', 8) <= 0) {
            return ['consumed' => [], 'remaining_to_allocate' => $sellSize];
        }

        $remaining = BigDecimal::of($sellSize);
        $consumed = [];
        $lots = PmPurchaseTracking::query()
            ->where('member_id', (int) $intent->member_id)
            ->where('token_id', (string) $intent->token_id)
            ->orderBy('opened_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (PmPurchaseTracking $lot) => preg_match('/^\d+(\.\d+)?$/', (string) $lot->remaining_size) === 1 && bccomp((string) $lot->remaining_size, '0', 8) > 0)
            ->values();

        foreach ($lots as $lot) {
            if ($remaining->isLessThanOrEqualTo(BigDecimal::zero())) {
                break;
            }

            $available = BigDecimal::of((string) $lot->remaining_size);
            $consume = $available->isLessThan($remaining) ? $available : $remaining;
            if ($consume->isLessThanOrEqualTo(BigDecimal::zero())) {
                continue;
            }

            $lot->remaining_size = $available->minus($consume)->toScale(8, RoundingMode::DOWN)->stripTrailingZeros()->__toString();
            if (bccomp((string) $lot->remaining_size, '0', 8) <= 0) {
                $lot->remaining_size = '0';
                $lot->closed_at = now();
            }
            $lot->meta = array_merge(is_array($lot->meta) ? $lot->meta : [], [
                'last_sell_order_id' => $order->id,
                'last_sell_intent_id' => $intent->id,
            ]);
            $lot->save();

            $consumed[] = [
                'purchase_tracking_id' => $lot->id,
                'consumed_size' => $consume->toScale(8, RoundingMode::DOWN)->stripTrailingZeros()->__toString(),
                'remaining_size' => (string) $lot->remaining_size,
            ];

            $remaining = $remaining->minus($consume);
        }

        return [
            'consumed' => $consumed,
            'remaining_to_allocate' => $remaining->toScale(8, RoundingMode::DOWN)->stripTrailingZeros()->__toString(),
        ];
    }

    public function getOpenQuantityByToken(int $memberId, int $copyTaskId, string $tokenId): string
    {
        $tokenId = trim($tokenId);
        if ($memberId <= 0 || $copyTaskId <= 0 || $tokenId === '') {
            return '0';
        }

        $total = PmPurchaseTracking::query()
            ->where('member_id', $memberId)
            ->where('copy_task_id', $copyTaskId)
            ->where('token_id', $tokenId)
            ->get()
            ->reduce(function (BigDecimal $carry, PmPurchaseTracking $lot) {
                $remaining = (string) $lot->remaining_size;
                if (preg_match('/^\d+(\.\d+)?$/', $remaining) !== 1 || bccomp($remaining, '0', 8) <= 0) {
                    return $carry;
                }

                return $carry->plus(BigDecimal::of($remaining));
            }, BigDecimal::zero());

        return $total->toScale(8, RoundingMode::DOWN)->stripTrailingZeros()->__toString();
    }

    public function getPendingBuyQuantityByToken(int $memberId, int $copyTaskId, string $tokenId, ?int $excludeIntentId = null): string
    {
        $tokenId = trim($tokenId);
        if ($memberId <= 0 || $copyTaskId <= 0 || $tokenId === '') {
            return '0';
        }

        $trackedIntentIds = PmPurchaseTracking::query()
            ->where('member_id', $memberId)
            ->where('copy_task_id', $copyTaskId)
            ->where('token_id', $tokenId)
            ->whereNotNull('order_intent_id')
            ->pluck('order_intent_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = PmOrderIntent::query()
            ->where('member_id', $memberId)
            ->where('copy_task_id', $copyTaskId)
            ->where('token_id', $tokenId)
            ->where('side', PolymarketTradingService::SIDE_BUY)
            ->whereIn('status', [PmOrderIntent::STATUS_PENDING, PmOrderIntent::STATUS_SUBMITTED]);

        if ($excludeIntentId !== null) {
            $query->where('id', '!=', $excludeIntentId);
        }

        if ($trackedIntentIds !== []) {
            $query->whereNotIn('id', $trackedIntentIds);
        }

        $total = $query->get()
            ->reduce(function (BigDecimal $carry, PmOrderIntent $intent) {
                $planned = (string) ($intent->risk_snapshot['planned_quantity'] ?? $intent->decision_payload['sizing']['planned_quantity'] ?? '0');
                if (preg_match('/^\d+(\.\d+)?$/', $planned) !== 1 || bccomp($planned, '0', 8) <= 0) {
                    return $carry;
                }

                return $carry->plus(BigDecimal::of($planned));
            }, BigDecimal::zero());

        return $total->toScale(8, RoundingMode::DOWN)->stripTrailingZeros()->__toString();
    }

    private function resolveFilledSize(PmOrder $order): ?string
    {
        foreach ([
            $order->filled_size,
            $order->request_payload['response']['size_matched'] ?? null,
            $order->request_payload['response']['filled_size'] ?? null,
            $order->response_payload['size_matched'] ?? null,
            $order->response_payload['filled_size'] ?? null,
            $order->request_payload['normalized_size'] ?? null,
        ] as $candidate) {
            if ((is_string($candidate) || is_int($candidate) || is_float($candidate)) && preg_match('/^\d+(\.\d+)?$/', (string) $candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
