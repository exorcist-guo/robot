<?php

namespace App\Services\Pm;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class SkipRoundPredictService
{
    public function __construct(private readonly TailSweepMarketDataService $marketData)
    {
    }

    /**
     * 隔一轮预测入口。
     *
     * 现在不再使用本地 round open price 做方向判断，
     * 改为直接请求本地预测接口 /predict/latest：
     * - status 必须为 ok
     * - is_stale 必须为 false
     * - is_actionable 必须为 true
     * - signal 只能是 UP / DOWN
     *
     * 被预测轮次时间来自接口字段 target_bar_time。
     * 当前命令后续仍然依赖 target_round_key / next_round_start / next_round_end 等上下文，
     * 所以这里会把 target_bar_time 映射回原有结构，避免下游执行链路大改。
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function predict(array $config, Carbon $now): array
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
            ];
        }

        $isFifteenMinutes = str_contains($baseSlug, '15m');
        $roundSpan = $isFifteenMinutes ? 900 : 300;
        $currentRoundStart = $isFifteenMinutes
            ? $this->marketData->getRoundStartTime15($now)
            : $this->marketData->getRoundStartTime($now);
        $currentRoundEnd = $currentRoundStart + $roundSpan;

        try {
            $response = Http::timeout(5)->get('http://127.0.0.1:8000/predict/latest');
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'reason' => 'predict_api_request_failed',
                'message' => $e->getMessage(),
            ];
        }

        if (!$response->successful()) {
            return [
                'ok' => false,
                'reason' => 'predict_api_http_error',
                'status_code' => $response->status(),
                'body' => $response->body(),
            ];
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            return [
                'ok' => false,
                'reason' => 'predict_api_invalid_json',
                'body' => $response->body(),
            ];
        }

        if (($payload['status'] ?? null) !== 'ok') {
            return [
                'ok' => false,
                'reason' => 'predict_api_status_not_ok',
                'payload' => $payload,
            ];
        }

        if (($payload['is_stale'] ?? null) !== false) {
            return [
                'ok' => false,
                'reason' => 'predict_api_stale',
                'payload' => $payload,
            ];
        }

        if (($payload['is_actionable'] ?? null) !== true) {
            return [
                'ok' => false,
                'reason' => 'predict_api_not_actionable',
                'payload' => $payload,
            ];
        }

        $signal = strtoupper(trim((string) ($payload['signal'] ?? '')));
        if (!in_array($signal, ['UP', 'DOWN'], true)) {
            return [
                'ok' => false,
                'reason' => 'predict_api_invalid_signal',
                'payload' => $payload,
            ];
        }

        $targetBarTime = (int) ($payload['target_bar_time'] ?? 0);
        if ($targetBarTime <= 0) {
            return [
                'ok' => false,
                'reason' => 'predict_api_missing_target_bar_time',
                'payload' => $payload,
            ];
        }

        $nextRoundStart = $targetBarTime;
        $nextRoundEnd = $nextRoundStart + $roundSpan;

        return [
            'ok' => true,
            'base_slug' => $baseSlug,
            'prediction_round_key' => (string) $currentRoundEnd,
            'signal_round_key' => (string) $currentRoundEnd,
            'target_round_key' => (string) $nextRoundEnd,
            'current_round_start' => $currentRoundStart,
            'current_round_end' => $currentRoundEnd,
            'next_round_start' => $nextRoundStart,
            'next_round_end' => $nextRoundEnd,
            'remaining_seconds' => max(0, $now->diffInSeconds(Carbon::createFromTimestamp($currentRoundEnd), false)),
            'predict_diff' => (string) ($payload['predict_diff'] ?? $payload['diff'] ?? '0'),
            'predict_abs_diff' => (string) ($payload['predict_abs_diff'] ?? $payload['abs_diff'] ?? '0'),
            'predicted_side' => $signal === 'UP' ? 'up' : 'down',
            'predict_api_payload' => $payload,
        ];
    }
}
