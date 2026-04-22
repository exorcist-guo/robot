<?php

namespace App\Console\Commands;

use App\Jobs\PmExecuteOrderIntentJob;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketTradingService;
use App\Services\Pm\TailSweepMarketDataService;
use App\Services\Pm\TailSweepNextRoundService;
use Illuminate\Console\Command;

class PmPreplaceNextRoundOrderCommand extends Command
{
    private const HARDCODED_CONFIG = [
        'task_label' => 'member-7-btc-next-round',
        'copy_task_id' => 0,
        'member_id' => 7,
        'market_slug' => 'btc-updown-5m-1775908800',
        'market_id' => '1956222',
        'market_question' => 'Bitcoin Up or Down - April 13, 5:20AM-5:25AM ET',
        'market_symbol' => 'btc/usd',
        'resolution_source' => 'https://data.chain.link/streams/btc-usd',
        'token_yes_id' => '67911685367438010117412622161650549871800124791516212531878197495633969519784',
        'token_no_id' => '94536462316742725904526691949689575556522574605021930087807817684758931474464',
        'tail_order_usdc' => 10000000,
        'max_slippage_bps' => 50,
        'allow_partial_fill' => true,
        'daily_max_usdc' => null,
        'next_round_min_predict_diff' => '10',
        'next_round_prepare_seconds' => 999999,
    ];

    protected $signature = 'pm:preplace-next-round-order {--once : 仅执行一次，便于调试}';

    protected $description = '参考模式一信号，提前为下一轮市场创建真实下单意图';

    public function handle(
        GammaClient $gammaClient,
        TailSweepNextRoundService $nextRoundService,
        TailSweepMarketDataService $marketData,
        PolymarketTradingService $trading
    ): int {
        $once = (bool) $this->option('once');
        $config = self::HARDCODED_CONFIG;

        do {
            $now = now();
            $prepared = $nextRoundService->prepare($config, $gammaClient, $now);
            if (($prepared['ok'] ?? false) !== true) {
                $reason = (string) ($prepared['reason'] ?? 'unknown');
                $context = collect($prepared)
                    ->except(['ok'])
                    ->map(fn ($value) => is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    ->all();
                $this->line(($config['task_label'] ?? 'hardcoded-task').' 跳过: '.$reason.' '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                if ($once) {
                    return self::SUCCESS;
                }

                sleep(5);
                continue;
            }

            $targetRoundKey = (string) ($prepared['target_round_key'] ?? '');
            if ($targetRoundKey === '') {
                if ($once) {
                    return self::SUCCESS;
                }

                sleep(5);
                continue;
            }

            $existingIntent = PmOrderIntent::query()
                ->where('member_id', (int) $config['member_id'])
                ->where('status', PmOrderIntent::STATUS_PENDING)
                ->where('risk_snapshot->strategy', 'next_round_preorder')
                ->where('risk_snapshot->target_round_key', $targetRoundKey)
                ->first();
            if ($existingIntent) {
                $this->line(($config['task_label'] ?? 'hardcoded-task').' 跳过: existing_pending_intent '.json_encode([
                    'intent_id' => $existingIntent->id,
                    'target_round_key' => $targetRoundKey,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                if ($once) {
                    return self::SUCCESS;
                }

                sleep(5);
                continue;
            }

            $predictedSide = (string) $prepared['predicted_side'];
            $side = PolymarketTradingService::SIDE_BUY;
            $tokenId = (string) ($prepared['token_id'] ?? '');
            if ($tokenId === '' || !$trading->isTokenTradable($tokenId)) {
                $this->line(($config['task_label'] ?? 'hardcoded-task').' 跳过: token_not_tradable '.json_encode([
                    'token_id' => $tokenId,
                    'target_round_key' => $targetRoundKey,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                if ($once) {
                    return self::SUCCESS;
                }

                sleep(5);
                continue;
            }

            $books = [];
            [$entryPrice, $entryPriceSource] = $marketData->resolveEntryPrice(
                $trading,
                $tokenId,
                $side,
                (string) $config['tail_order_usdc'],
                $books
            );
            if (!preg_match('/^\d+(\.\d+)?$/', $entryPrice) || bccomp($entryPrice, '0', 8) <= 0) {
                $this->line(($config['task_label'] ?? 'hardcoded-task').' 跳过: invalid_entry_price '.json_encode([
                    'entry_price' => $entryPrice,
                    'entry_price_source' => $entryPriceSource,
                    'target_round_key' => $targetRoundKey,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                if ($once) {
                    return self::SUCCESS;
                }

                sleep(5);
                continue;
            }

            $nextMarket = is_array($prepared['next_market'] ?? null) ? $prepared['next_market'] : [];
            $intent = PmOrderIntent::create([
                'copy_task_id' => (int) $config['copy_task_id'],
                'leader_trade_id' => null,
                'member_id' => (int) $config['member_id'],
                'token_id' => $tokenId,
                'side' => $side,
                'leader_price' => $entryPrice,
                'target_usdc' => (int) $config['tail_order_usdc'],
                'clamped_usdc' => (int) $config['tail_order_usdc'],
                'status' => PmOrderIntent::STATUS_PENDING,
                'skip_reason' => null,
                'risk_snapshot' => [
                    'mode' => 'tail_sweep',
                    'strategy' => 'next_round_preorder',
                    'task_label' => (string) ($config['task_label'] ?? 'hardcoded-task'),
                    'config_source' => 'hardcoded_command',
                    'prediction_source' => 'mode1_open_price',
                    'max_slippage_bps' => $config['max_slippage_bps'],
                    'allow_partial_fill' => (bool) $config['allow_partial_fill'],
                    'daily_max_usdc' => $config['daily_max_usdc'],
                    'prediction_round_key' => (string) ($prepared['prediction_round_key'] ?? ''),
                    'target_round_key' => $targetRoundKey,
                    'prev_round_open_price' => (string) ($prepared['prev_round_open_price'] ?? '0'),
                    'current_round_open_price' => (string) ($prepared['current_round_open_price'] ?? '0'),
                    'predict_diff' => (string) ($prepared['predict_diff'] ?? '0'),
                    'predict_abs_diff' => (string) ($prepared['predict_abs_diff'] ?? '0'),
                    'predicted_side' => $predictedSide,
                    'next_round_slug' => (string) ($prepared['next_round_slug'] ?? ''),
                    'next_market_id' => (string) ($nextMarket['market_id'] ?? ''),
                    'next_market_end_at' => (string) ($prepared['next_round_end'] ?? ''),
                    'market_slug' => (string) ($nextMarket['slug'] ?? ''),
                    'market_id' => (string) ($nextMarket['market_id'] ?? ''),
                    'market_question' => (string) ($nextMarket['question'] ?? ''),
                    'resolution_source' => (string) ($nextMarket['resolution_source'] ?? ''),
                    'trigger_side' => $predictedSide,
                    'token_yes_id' => (string) ($nextMarket['token_yes_id'] ?? ''),
                    'token_no_id' => (string) ($nextMarket['token_no_id'] ?? ''),
                    'entry_price' => $entryPrice,
                    'entry_price_source' => $entryPriceSource,
                    'remaining_seconds' => (int) ($prepared['remaining_seconds'] ?? 0),
                    'input_config' => $config,
                ],
                'price_time_limit' => 'mode1-next-round',
            ]);

            PmExecuteOrderIntentJob::dispatch($intent->id);
            $this->info(($config['task_label'] ?? 'hardcoded-task')." 已创建下一轮预下单意图: {$intent->id}");

            if ($once) {
                return self::SUCCESS;
            }

            sleep(5);
        } while (true);
    }
}
