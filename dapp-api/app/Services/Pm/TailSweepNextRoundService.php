<?php

namespace App\Services\Pm;

use App\Models\Pm\PmTailSweepRoundOpenPrice;
use Illuminate\Support\Carbon;

class TailSweepNextRoundService
{
    public function __construct(private readonly TailSweepMarketDataService $marketData)
    {
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function prepare(array $config, GammaClient $gammaClient, Carbon $now): array
    {
        $marketSlug = (string) ($config['market_slug'] ?? '');
        $baseSlug = $this->marketData->normalizeBaseSlug($marketSlug);
        if ($baseSlug === '') {
            return ['ok' => false, 'reason' => 'missing_market_slug'];
        }

        if (!str_contains($baseSlug, 'updown-5m') && !str_contains($baseSlug, 'updown-15m')) {
            return [
                'ok' => false,
                'reason' => 'unsupported_round_slug',
                'market_slug' => $marketSlug,
                'base_slug' => $baseSlug,
            ];
        }

        $isFifteenMinutes = str_contains($baseSlug, '15m');
        $roundSpan = $isFifteenMinutes ? 900 : 300;
        $currentRoundStart = $isFifteenMinutes
            ? $this->marketData->getRoundStartTime15($now)
            : $this->marketData->getRoundStartTime($now);
        $currentRoundEnd = $currentRoundStart + $roundSpan;
        $prevRoundStart = $currentRoundStart - $roundSpan;
        $nextRoundStart = $currentRoundEnd;
        $nextRoundEnd = $nextRoundStart + $roundSpan;

        $remainingSeconds = max(0, $now->diffInSeconds(Carbon::createFromTimestamp($currentRoundEnd), false));
        $prepareSeconds = max(1, (int) (($config['next_round_prepare_seconds'] ?? 20) ?: 20));
        if ($remainingSeconds > $prepareSeconds) {
            return [
                'ok' => false,
                'reason' => 'not_in_prepare_window',
                'remaining_seconds' => $remainingSeconds,
                'prepare_seconds' => $prepareSeconds,
            ];
        }

        $symbol = (string) (($config['market_symbol'] ?? 'btc/usd') ?: 'btc/usd');
        $prevOpen = $this->getRoundOpenPrice($symbol, $prevRoundStart, $prevRoundStart + $roundSpan);
        $currentOpen = $this->getRoundOpenPrice($symbol, $currentRoundStart, $currentRoundEnd);
        if ($prevOpen === null || $currentOpen === null) {
            return [
                'ok' => false,
                'reason' => 'missing_round_open_price',
                'prev_round_start' => $prevRoundStart,
                'current_round_start' => $currentRoundStart,
            ];
        }

        $predict = $this->predictNextSide($config, $prevOpen, $currentOpen);
        if (($predict['ok'] ?? false) !== true) {
            return $predict + [
                'prev_round_open_price' => $prevOpen,
                'current_round_open_price' => $currentOpen,
                'prediction_round_key' => (string) $currentRoundEnd,
                'target_round_key' => (string) $nextRoundEnd,
            ];
        }

        $nextRoundSlug = $this->marketData->buildRoundSlug($baseSlug, $nextRoundStart);
        $nextMarket = $this->marketData->resolveMarketBySlug($gammaClient, $nextRoundSlug);
        if (!is_array($nextMarket) || $nextMarket === []) {
            return [
                'ok' => false,
                'reason' => 'next_market_not_ready',
                'next_round_slug' => $nextRoundSlug,
            ];
        }

        $predictedSide = (string) $predict['predicted_side'];
        $tokenId = $predictedSide === 'up'
            ? (string) ($nextMarket['token_yes_id'] ?? '')
            : (string) ($nextMarket['token_no_id'] ?? '');
        if ($tokenId === '') {
            return [
                'ok' => false,
                'reason' => 'missing_target_token',
                'predicted_side' => $predictedSide,
                'next_round_slug' => $nextRoundSlug,
            ];
        }

        return [
            'ok' => true,
            'base_slug' => $baseSlug,
            'prediction_round_key' => (string) $currentRoundEnd,
            'target_round_key' => (string) $nextRoundEnd,
            'prev_round_start' => $prevRoundStart,
            'current_round_start' => $currentRoundStart,
            'current_round_end' => $currentRoundEnd,
            'next_round_start' => $nextRoundStart,
            'next_round_end' => $nextRoundEnd,
            'remaining_seconds' => $remainingSeconds,
            'prev_round_open_price' => $prevOpen,
            'current_round_open_price' => $currentOpen,
            'predict_diff' => $predict['predict_diff'],
            'predict_abs_diff' => $predict['predict_abs_diff'],
            'predicted_side' => $predictedSide,
            'next_round_slug' => $nextRoundSlug,
            'next_market' => $nextMarket,
            'token_id' => $tokenId,
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function predictNextSide(array $config, string $prevOpen, string $currentOpen): array
    {
        if (!$this->isPositiveDecimal($prevOpen) || !$this->isPositiveDecimal($currentOpen)) {
            return ['ok' => false, 'reason' => 'invalid_round_open_price'];
        }

        $predictDiff = bcsub($currentOpen, $prevOpen, 8);
        $predictAbsDiff = $predictDiff[0] === '-' ? bcmul($predictDiff, '-1', 8) : $predictDiff;
        $minPredictDiff = trim((string) (($config['next_round_min_predict_diff'] ?? '10') ?: '10'));
        if ($minPredictDiff === '' || !preg_match('/^\d+(\.\d+)?$/', $minPredictDiff)) {
            $minPredictDiff = '10';
        }

        if (bccomp($predictAbsDiff, $minPredictDiff, 8) === -1) {
            return [
                'ok' => false,
                'reason' => 'predict_diff_below_threshold',
                'predict_diff' => $predictDiff,
                'predict_abs_diff' => $predictAbsDiff,
                'min_predict_diff' => $minPredictDiff,
            ];
        }

        return [
            'ok' => true,
            'predicted_side' => bccomp($currentOpen, $prevOpen, 8) === 1 ? 'up' : 'down',
            'predict_diff' => $predictDiff,
            'predict_abs_diff' => $predictAbsDiff,
            'min_predict_diff' => $minPredictDiff,
        ];
    }

    private function getRoundOpenPrice(string $symbol, int $roundStartAt, int $roundEndAt): ?string
    {
        $price = PmTailSweepRoundOpenPrice::query()
            ->where('symbol', $symbol)
            ->where('round_start_at', Carbon::createFromTimestamp($roundStartAt))
            ->value('round_open_price');

        if (is_string($price) && $price !== '') {
            return $price;
        }

        $fallback = $this->marketData->getStartPrice($roundStartAt, $roundEndAt, $symbol);
        return $fallback !== '0' ? $fallback : null;
    }

    private function isPositiveDecimal(string $value): bool
    {
        return preg_match('/^\d+(\.\d+)?$/', $value) === 1 && bccomp($value, '0', 8) === 1;
    }
}
