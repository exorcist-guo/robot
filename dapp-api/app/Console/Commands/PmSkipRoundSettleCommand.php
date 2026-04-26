<?php

namespace App\Console\Commands;

use App\Services\Pm\SkipRoundConfigProvider;
use App\Services\Pm\SkipRoundLineStateService;
use App\Services\Pm\SkipRoundSettlementService;
use Illuminate\Console\Command;

class PmSkipRoundSettleCommand extends Command
{
    /**
     * 结算命令。
     *
     * 当前实现虽然提供了 --once 选项，但本命令本身没有常驻循环，
     * 主要用于：
     * 1. 手工调试时执行一次；
     * 2. 交给调度器周期性调用。
     */
    protected $signature = 'pm:skip-round-settle {--once : 仅执行一次，便于调试}';

    /**
     * 结算已经到目标轮结束时间的隔一轮订单，
     * 并在结算完成后推进对应 A/B 线的资金状态。
     */
    protected $description = '隔一轮预测模块：结算到期订单并推进 A/B 资金状态';

    /**
     * 命令执行流程：
     * 1. 读取当前策略的硬编码配置；
     * 2. 确保策略主记录与 A/B 两条线存在；
     * 3. 只对当前策略下“已成交但未结算”的订单做结算；
     * 4. 输出本次成功结算的订单数量。
     */
    public function handle(
        SkipRoundConfigProvider $configProvider,
        SkipRoundLineStateService $lineStateService,
        SkipRoundSettlementService $settlementService,
    ): int {
        // 读取隔一轮模块的唯一配置入口。
        $config = $configProvider->get();

        // 启动/补齐策略主记录和 A/B 线记录。
        // 这里不是为了下单，而是为了让结算时一定能找到对应策略与资金线。
        $boot = $lineStateService->bootstrap($config);

        // 结算当前策略下所有“目标轮已结束”的未结算订单。
        $count = $settlementService->settleStrategy($boot['strategy'], $config);

        // 输出本轮实际完成的结算数量。
        $this->info('已结算订单数: '.$count);

        return self::SUCCESS;
    }
}
