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
use Illuminate\Console\Command;

class PmPreplaceNextRoundOrderCommand extends Command
{
    /**
     * 命令说明：
     * 这是“隔一轮预测模块”的主入口。
     *
     * 它的职责不是直接自己拼所有交易细节，而是串联以下几个步骤：
     * 1. 读取硬编码策略配置
     * 2. 初始化策略主记录和 A/B 两条线状态
     * 3. 读取开盘价并做模式1预测
     * 4. 解析下一轮市场并落库
     * 5. 创建本模块自己的订单记录
     * 6. 调执行服务去挂单、轮询、撤单、补市价
     * 7. 记录执行成功或失败的上下文
     */
    protected $signature = 'pm:preplace-next-round-order {--once : 仅执行一次，便于调试}';

    /**
     * 运行目标：
     * 在当前轮接近结束时，按模式1信号去预测下一轮方向，
     * 然后用 A/B 双线资金管理执行一笔下一轮订单。
     */
    protected $description = '隔一轮预测模块：A/B 双线预测下一轮并执行挂单、撤单、补单';

    public function handle(
        SkipRoundConfigProvider $configProvider,
        SkipRoundLineStateService $lineStateService,
        SkipRoundPredictService $predictService,
        SkipRoundMarketResolverService $marketResolver,
        SkipRoundExecutionService $executionService,
        GammaClient $gammaClient,
        PmPrivateKeyResolver $resolver,
    ): int {
        // --once=true 表示只跑一轮，便于联调；否则常驻每 5 秒循环一次。
        $once = (bool) $this->option('once');
        $config = $configProvider->get();

        do {
            /**
             * 第一步：初始化策略和 A/B 两条线。
             *
             * bootstrap() 会确保：
             * - pm_skip_round_strategies 存在当前策略主记录
             * - pm_skip_round_strategy_lines 存在 A/B 两条线
             * - 能取出当前这一次应该使用哪一条线下注
             */
            $boot = $lineStateService->bootstrap($config);
            $strategy = $boot['strategy'];
            $line = $boot['line'];
            $now = now();

            /**
             * 第二步：做模式1预测。
             *
             * 预测输入来自 pm_tail_sweep_round_open_prices：
             * - 上一轮开盘价
             * - 当前轮开盘价
             *
             * 预测输出包含：
             * - signal_round_key
             * - target_round_key
             * - predicted_side
             * - predict_diff / predict_abs_diff
             */
            $prediction = $predictService->predict($config, $now);

            if (($prediction['ok'] ?? false) !== true) {
                /**
                 * 预测失败时直接跳过。
                 * 常见原因：
                 * - 当前轮价差没达到最小阈值
                 * - 开盘价缺失
                 * - slug 不支持
                 */
                $this->line(($config['strategy_key'] ?? 'skip-round').' 跳过: '.json_encode($prediction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if ($once) {
                    return self::SUCCESS;
                }
                sleep(5);
                continue;
            }

            /**
             * 同一策略在同一 signal_round_key 上只处理一次，避免重复生成订单。
             */
            if ((string) ($strategy->last_signal_round_key ?? '') === (string) $prediction['signal_round_key']) {
                if ($once) {
                    return self::SUCCESS;
                }
                sleep(5);
                continue;
            }

            /**
             * 第三步：解析下一轮市场并落库。
             *
             * 这里会根据 base market slug + next round time：
             * - 生成下一轮完整 slug
             * - 查询 gamma market
             * - 写入 pm_skip_round_markets
             */
            $resolved = $marketResolver->resolveAndStore($strategy, $config, $prediction, $gammaClient);
            if (($resolved['ok'] ?? false) !== true) {
                $strategy->last_error = (string) ($resolved['reason'] ?? 'market_resolve_failed');
                $strategy->last_ran_at = now();
                $strategy->save();
                $this->line(($config['strategy_key'] ?? 'skip-round').' 跳过: '.json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if ($once) {
                    return self::SUCCESS;
                }
                sleep(5);
                continue;
            }

            /**
             * 根据预测方向决定买哪一个 token：
             * - up   -> token_yes_id
             * - down -> token_no_id
             */
            $market = $resolved['market'];
            $predictedSide = (string) $prediction['predicted_side'];
            $tokenId = $predictedSide === 'up'
                ? (string) ($market['token_yes_id'] ?? '')
                : (string) ($market['token_no_id'] ?? '');

            /**
             * 防重复：
             * 同一策略、同一目标轮次，只允许存在一条未失败/未结算的订单记录。
             */
            $existingOrder = PmSkipRoundOrder::query()
                ->where('strategy_id', $strategy->id)
                ->where('target_round_key', (string) $prediction['target_round_key'])
                ->whereNotIn('status', [PmSkipRoundOrder::STATUS_FAILED, PmSkipRoundOrder::STATUS_SETTLED])
                ->first();
            if ($existingOrder) {
                if ($once) {
                    return self::SUCCESS;
                }
                sleep(5);
                continue;
            }

            /**
             * 第四步：创建本模块自己的订单记录。
             *
             * 注意：这里不写 pm_order_intents / pm_orders，
             * 只写 pm_skip_round_orders。
             */
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
                'predict_diff' => (string) $prediction['predict_diff'],
                'predict_abs_diff' => (string) $prediction['predict_abs_diff'],
                'prev_round_open_price' => (string) $prediction['prev_round_open_price'],
                'current_round_open_price' => (string) $prediction['current_round_open_price'],
                'bet_amount' => (string) $line->current_bet_amount,
                'status' => PmSkipRoundOrder::STATUS_PREDICTED,
                'snapshot' => [
                    'config' => $config,
                    'prediction' => $prediction,
                    'market' => $market,
                ],
            ]);

            /**
             * 第五步：找到实际下单钱包。
             * 如果当前 member 没有托管钱包，则本次订单直接记失败。
             */
            $wallet = PmCustodyWallet::with('apiCredentials')
                ->where('member_id', (int) $config['member_id'])
                ->first();
            if (!$wallet) {
                $order->status = PmSkipRoundOrder::STATUS_FAILED;
                $order->fail_reason = 'missing_wallet';
                $order->save();
            } else {
                // 先验证私钥/凭证可解析，再进入真实交易执行。
                $resolver->resolve($wallet);
                try {
                    /**
                     * 第六步：执行真实下单链路。
                     *
                     * execute() 内部会继续做：
                     * - 查询盘口
                     * - 在 asks 中选价格最大的档位挂 BUY 单
                     * - 轮询挂单成交量
                     * - 剩余时间 <5 秒时撤单
                     * - 对剩余未成交金额执行市价补买
                     */
                    $executionService->execute($wallet, $order, $config, $market, (int) $prediction['current_round_end']);
                    $strategy->last_signal_round_key = (string) $prediction['signal_round_key'];
                    $strategy->last_target_round_key = (string) $prediction['target_round_key'];
                    $strategy->last_ran_at = now();
                    $strategy->last_error = null;
                    $strategy->save();
                    $lineStateService->rotate($strategy);
                    $this->info(($config['strategy_key'] ?? 'skip-round')." 已创建并执行隔一轮订单: {$order->id}");
                } catch (\Throwable $e) {
                    /**
                     * 真实交易过程中任何异常都要优雅落库，
                     * 不能让订单一直停留在 predicted 状态。
                     */
                    $order->refresh();
                    $order->status = PmSkipRoundOrder::STATUS_FAILED;
                    $order->fail_reason = str_contains(strtolower($e->getMessage()), 'timed out')
                        ? 'clob_connect_timeout'
                        : 'execution_exception';
                    $order->snapshot = array_merge($order->snapshot ?? [], [
                        'execution_exception' => [
                            'message' => $e->getMessage(),
                            'class' => $e::class,
                        ],
                    ]);
                    $order->save();

                    $strategy->last_error = $order->fail_reason;
                    $strategy->last_ran_at = now();
                    $strategy->save();

                    $this->error(($config['strategy_key'] ?? 'skip-round').' 执行失败: '.$e->getMessage());
                    if ($once) {
                        return self::FAILURE;
                    }
                }
            }

            if ($once) {
                return self::SUCCESS;
            }

            sleep(5);
        } while (true);
    }
}
