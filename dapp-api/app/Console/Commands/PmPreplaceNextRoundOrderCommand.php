<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCustodyWallet;
use App\Models\Pm\PmSkipRoundOrder;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PmPrivateKeyResolver;
use App\Services\Pm\SkipRoundConfigProvider;
use App\Services\Pm\SkipRoundExecutionService;
use App\Services\Pm\SkipRoundLineStateService;
use App\Services\Pm\SkipRoundMarketResolverService;
use App\Services\Pm\SkipRoundPredictService;
use Cache;
use Illuminate\Console\Command;

class PmPreplaceNextRoundOrderCommand extends Command
{
    /**
     * 命令说明：
     * 这是“隔一轮预测模块”的主入口。
     *
     * 现在它不再作为常驻循环进程，而是单次执行一次“预测/续跑”。
     * 这样服务停止后再次启动，只要调度器继续拉起，就能从数据库状态继续推进旧订单。
     */
    protected $signature = 'pm:preplace-next-round-order {--once : 仅执行一次，便于调试}';

    protected $description = '隔一轮预测模块：A/B 双线预测下一轮并执行挂单、撤单、补单';

    public function handle(
        SkipRoundConfigProvider $configProvider,
        SkipRoundLineStateService $lineStateService,
        SkipRoundPredictService $predictService,
        SkipRoundMarketResolverService $marketResolver,
        SkipRoundExecutionService $executionService,
        GammaClient $gammaClient,
        PmPrivateKeyResolver $resolver,
    ) {
        $runOnce = (bool) $this->option('once');


            while (true) {
                try {
                if (!$runOnce) {
                    sleep(5); // 常驻模式下每 5 秒执行一次，避免过于频繁地查询数据库和远程接口
                }
                $this->reconnectRedis();
                var_dump(date('Y-m-d H:i:s ') . '我开始跑了');
                $config = $configProvider->get();

                $boot = $lineStateService->bootstrap($config);
                $strategy = $boot['strategy'];

                // 先续跑旧单，保证服务中断后重启能从中间状态继续推进。
                $pendingOrder = PmSkipRoundOrder::query()
                    ->where('strategy_id', $strategy->id)
                    ->whereIn('status', PmSkipRoundOrder::ACTIVE_STATUSES)
                    ->orderBy('id')
                    ->first();

                if ($pendingOrder) {
                    $wallet = PmCustodyWallet::with('apiCredentials')
                        ->where('member_id', (int) $config['member_id'])
                        ->first();

                    if (!$wallet) {
                        $pendingOrder->status = PmSkipRoundOrder::STATUS_FAILED;
                        $pendingOrder->fail_reason = 'missing_wallet';
                        $pendingOrder->save();
                        $this->error(($config['strategy_key'] ?? 'skip-round') . ' 续跑失败: missing_wallet');
                        if ($runOnce) {
                            return self::FAILURE;
                        }
                        continue;
                    }

                    $resolver->resolve($wallet);
                    $market = is_array($pendingOrder->snapshot['market'] ?? null) ? $pendingOrder->snapshot['market'] : [];
                    $currentRoundEnd = (int) ($pendingOrder->snapshot['prediction']['current_round_end'] ?? 0);

                    try {
                        $executionService->advance($wallet, $pendingOrder, $config, $market, $currentRoundEnd);
                        $this->info(($config['strategy_key'] ?? 'skip-round') . " 已续跑隔一轮订单: {$pendingOrder->id}");
                        if ($runOnce) {
                            return self::SUCCESS;
                        }
                        continue;
                    } catch (\Throwable $e) {
                        $pendingOrder->refresh();
                        $message = $e->getMessage();
                        $pendingOrder->status = PmSkipRoundOrder::STATUS_FAILED;
                        $pendingOrder->fail_reason = str_contains(strtolower($message), 'timed out')
                            ? 'clob_connect_timeout'
                            : (str_contains($message, 'No orderbook exists for the requested token id')
                                ? 'missing_remote_orderbook'
                                : 'execution_exception');
                        $pendingOrder->snapshot = array_merge($pendingOrder->snapshot ?? [], [
                            'execution_exception' => [
                                'message' => $message,
                                'class' => $e::class,
                            ],
                        ]);
                        $pendingOrder->save();

                        $strategy->last_error = $pendingOrder->fail_reason;
                        $strategy->last_ran_at = now();
                        $strategy->save();

                        $this->error(($config['strategy_key'] ?? 'skip-round') . ' 续跑失败: ' . $message);
                        if ($runOnce) {
                            return self::FAILURE;
                        }
                        continue;
                    }
                }

                $line = $boot['line'];
                $now = now();
                $prediction = $predictService->predict($config, $now);

                if (($prediction['ok'] ?? false) !== true) {
                    $this->line(($config['strategy_key'] ?? 'skip-round') . ' 跳过: ' . json_encode($prediction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if ($runOnce) {
                        return self::SUCCESS;
                    }
                    continue;
                }

                $resolved = $marketResolver->resolveAndStore($strategy, $config, $prediction, $gammaClient);
                if (($resolved['ok'] ?? false) !== true) {
                    $strategy->last_error = (string) ($resolved['reason'] ?? 'market_resolve_failed');
                    $strategy->last_ran_at = now();
                    $strategy->save();
                    $this->line(($config['strategy_key'] ?? 'skip-round') . ' 跳过: ' . json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if ($runOnce) {
                        return self::SUCCESS;
                    }
                    continue;
                }

                $market = $resolved['market'];
                $predictedSide = (string) $prediction['predicted_side'];
                $tokenId = $predictedSide === 'up'
                    ? (string) ($market['token_yes_id'] ?? '')
                    : (string) ($market['token_no_id'] ?? '');

                $existingOrder = PmSkipRoundOrder::query()
                    ->where('strategy_id', $strategy->id)
                    ->where('target_round_key', (string) $prediction['target_round_key'])
                    ->first();
                if ($existingOrder) {
                    if (in_array((string) $existingOrder->status, PmSkipRoundOrder::ACTIVE_STATUSES, true)) {
                        $this->line(($config['strategy_key'] ?? 'skip-round') . " 当前轮已存在活跃订单: {$existingOrder->id}");
                    } else {
                        $this->line(($config['strategy_key'] ?? 'skip-round') . " 当前轮已存在历史订单，跳过重复创建: {$existingOrder->id} [{$existingOrder->status}]");
                    }
                    if ($runOnce) {
                        return self::SUCCESS;
                    }
                    continue;
                }

                $order = PmSkipRoundOrder::create([
                    'strategy_id' => $strategy->id,
                    'strategy_line_id' => $line->id,
                    'member_id' => (int) $config['member_id'],
                    'line_code' => $line->line_code,
                    'signal_round_key' => (string) $prediction['signal_round_key'],
                    'target_round_key' => (string) $prediction['target_round_key'],
                    'prediction_source_round_key' => (string) $prediction['prediction_round_key'],
                    'market_id' => (string) ($market['market_id'] ?? ''),
                    'market_slug' => (string) ($market['slug'] ?? ''),
                    'token_id' => $tokenId,
                    'predicted_side' => $predictedSide,
                    'order_side' => 'BUY',
                    'predict_diff' => (string) ($prediction['predict_diff'] ?? '0'),
                    'predict_abs_diff' => (string) ($prediction['predict_abs_diff'] ?? '0'),
                    'prev_round_open_price' => (string) ($prediction['prev_round_open_price'] ?? '0'),
                    'current_round_open_price' => (string) ($prediction['current_round_open_price'] ?? '0'),
                    'bet_amount' => (string) $line->current_bet_amount,
                    'status' => PmSkipRoundOrder::STATUS_PREDICTED,
                    'snapshot' => [
                        'config' => $config,
                        'prediction' => $prediction,
                        'market' => $market,
                    ],
                ]);

                if ($tokenId === '') {
                    $order->status = PmSkipRoundOrder::STATUS_FAILED;
                    $order->fail_reason = 'missing_token_id';
                    $order->save();
                    $strategy->last_error = 'missing_token_id';
                    $strategy->last_ran_at = now();
                    $strategy->save();
                    $this->error(($config['strategy_key'] ?? 'skip-round') . ' 执行失败: missing_token_id');
                    if ($runOnce) {
                        return self::FAILURE;
                    }
                    continue;
                }

                $wallet = PmCustodyWallet::with('apiCredentials')
                    ->where('member_id', (int) $config['member_id'])
                    ->first();
                if (!$wallet) {
                    $order->status = PmSkipRoundOrder::STATUS_FAILED;
                    $order->fail_reason = 'missing_wallet';
                    $order->save();
                    $this->error(($config['strategy_key'] ?? 'skip-round') . ' 执行失败: missing_wallet');
                    if ($runOnce) {
                        return self::FAILURE;
                    }
                    continue;
                }

                $resolver->resolve($wallet);
                try {
                    $executionService->advance($wallet, $order, $config, $market, (int) $prediction['current_round_end']);
                    $strategy->last_signal_round_key = (string) $prediction['signal_round_key'];
                    $strategy->last_target_round_key = (string) $prediction['target_round_key'];
                    $strategy->last_ran_at = now();
                    $strategy->last_error = null;
                    $strategy->save();
                    $lineStateService->rotate($strategy);
                    $this->info(($config['strategy_key'] ?? 'skip-round') . " 已创建并执行隔一轮订单: {$order->id}");
                    if ($runOnce) {
                        return self::SUCCESS;
                    }
                    continue;
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    $order->status = PmSkipRoundOrder::STATUS_FAILED;
                    $order->fail_reason = str_contains(strtolower($message), 'timed out')
                        ? 'clob_connect_timeout'
                        : (str_contains(strtolower($message), 'not enough balance / allowance')
                            ? 'insufficient_balance_or_allowance'
                            : 'execution_exception');
                    $order->snapshot = array_merge($order->snapshot ?? [], [
                        'execution_exception' => [
                            'message' => $message,
                            'class' => $e::class,
                        ],
                    ]);
                    $order->save();

                    $strategy->last_error = $order->fail_reason;
                    $strategy->last_ran_at = now();
                    $strategy->save();

                    $this->error(($config['strategy_key'] ?? 'skip-round') . ' 执行失败: ' . $message);
                    if ($runOnce) {
                        return self::FAILURE;
                    }
                    continue;
                }
            } catch (\Throwable $e) {
                $this->error('执行失败: ' . $e->getMessage());
                continue;
            }
            }

    }


    /**
     * 每轮开始前主动断开并重建 Redis 连接，避免长时间常驻后拿到失效连接。
     */
    private function reconnectRedis(): void
    {
        // 先处理 Cache 使用的 redis store。
        try {
            $store = Cache::getStore();
            if (method_exists($store, 'disconnect')) {
                $store->disconnect();
            }
        } catch (\Throwable) {
        }

        try {
            Cache::purge();
        } catch (\Throwable) {
        }

        try {
            $app = app();
            $app->forgetInstance('cache');
            $app->forgetInstance('cache.store');
        } catch (\Throwable) {
        }

        // 再处理 Redis Facade 连接。
        try {
            \Illuminate\Support\Facades\Redis::connection()->disconnect();
        } catch (\Throwable) {
        }

        try {
            \Illuminate\Support\Facades\Redis::purge();
        } catch (\Throwable) {
        }

        // 主动重新建连并 ping，一旦这里能成功，说明本轮拿到的是新连接。
        try {
            Cache::store()->get('pm:skip-round:redis:probe');
        } catch (\Throwable) {
        }

        try {
            \Illuminate\Support\Facades\Redis::connection()->ping();
        } catch (\Throwable) {
        }
    }
}
