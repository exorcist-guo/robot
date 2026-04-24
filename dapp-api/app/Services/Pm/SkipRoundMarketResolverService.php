<?php

namespace App\Services\Pm;

use App\Models\Pm\PmSkipRoundMarket;
use App\Models\Pm\PmSkipRoundStrategy;
use Illuminate\Support\Carbon;

class SkipRoundMarketResolverService
{
    public function __construct(
        private readonly TailSweepMarketDataService $marketData,
    ) {
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $prediction
     * @return array<string,mixed>
     */
    public function resolveAndStore(PmSkipRoundStrategy $strategy, array $config, array $prediction, GammaClient $gammaClient): array
    {
        // 优先使用预测结果里已经归一化好的 base_slug；
        // 如果没有，就从配置里的 market_slug 再做一次归一化。
        $baseSlug = (string) ($prediction['base_slug'] ?? $this->marketData->normalizeBaseSlug((string) ($config['market_slug'] ?? '')));

        // 预测阶段已经算好了下一轮的开始/结束时间，以及这一轮的唯一标识 round_key。
        $nextRoundStart = (int) ($prediction['next_round_start'] ?? 0);
        $nextRoundEnd = (int) ($prediction['next_round_end'] ?? 0);
        $targetRoundKey = (string) ($prediction['target_round_key'] ?? '');

        // 解析下一轮市场至少依赖：
        // - base slug
        // - 下一轮时间范围
        // - 本地唯一 round_key
        // 任一缺失都说明上游预测上下文不完整，不能继续往下走。
        if ($baseSlug === '' || $nextRoundStart <= 0 || $nextRoundEnd <= 0 || $targetRoundKey === '') {
            return ['ok' => false, 'reason' => 'invalid_prediction_context'];
        }

        // 按“基础 slug + 下一轮开始时间”拼出下一轮的真实 round slug。
        $roundSlug = $this->marketData->buildRoundSlug($baseSlug, $nextRoundStart);

        // 调用 Gamma 解析该 slug 对应的市场详情。
        $market = $this->marketData->resolveMarketBySlug($gammaClient, $roundSlug);
        if (!is_array($market) || $market === []) {
            // 如果下一轮市场还没出现在远端接口里，也先在本地落一条 not_ready 记录，
            // 方便后续继续补查，也方便排障时知道系统已经尝试过解析这一轮。
            $record = PmSkipRoundMarket::updateOrCreate(
                ['strategy_id' => $strategy->id, 'round_key' => $targetRoundKey],
                [
                    'round_start_at' => Carbon::createFromTimestamp($nextRoundStart),
                    'round_end_at' => Carbon::createFromTimestamp($nextRoundEnd),
                    'base_slug' => $baseSlug,
                    'round_slug' => $roundSlug,
                    'status' => 'not_ready',
                    'resolved_at' => null,
                ]
            );

            return ['ok' => false, 'reason' => 'next_market_not_ready', 'record_id' => $record->id, 'round_slug' => $roundSlug];
        }

        // 成功解析到市场后，把后续执行和结算需要的关键字段持久化到独立表：
        // - market_id / question / resolution_source
        // - yes/no token
        // - price_to_beat
        // - 原始 market payload
        // 这样后续步骤就不需要反复请求 Gamma。
        $record = PmSkipRoundMarket::updateOrCreate(
            ['strategy_id' => $strategy->id, 'round_key' => $targetRoundKey],
            [
                'round_start_at' => Carbon::createFromTimestamp($nextRoundStart),
                'round_end_at' => Carbon::createFromTimestamp($nextRoundEnd),
                'base_slug' => $baseSlug,
                'round_slug' => $roundSlug,
                'market_id' => (string) ($market['market_id'] ?? ''),
                'question' => (string) ($market['question'] ?? ''),
                'resolution_source' => (string) ($market['resolution_source'] ?? ''),
                'token_yes_id' => (string) ($market['token_yes_id'] ?? ''),
                'token_no_id' => (string) ($market['token_no_id'] ?? ''),
                'price_to_beat' => (string) ($market['price_to_beat'] ?? ''),
                'market_payload' => $market,
                'status' => 'resolved',
                'resolved_at' => now(),
            ]
        );

        // 返回远端市场信息和本地记录，供执行服务继续挂单/补单。
        return ['ok' => true, 'market' => $market, 'record' => $record];
    }
}
