<?php

namespace App\Console\Commands;

use App\Services\Pm\SkipRoundConfigProvider;
use App\Services\Pm\SkipRoundLineStateService;
use App\Services\Pm\SkipRoundSettlementService;
use Illuminate\Console\Command;

class PmSkipRoundSettleCommand extends Command
{
    protected $signature = 'pm:skip-round-settle {--once : 仅执行一次，便于调试}';

    protected $description = '隔一轮预测模块：结算到期订单并推进 A/B 资金状态';

    public function handle(
        SkipRoundConfigProvider $configProvider,
        SkipRoundLineStateService $lineStateService,
        SkipRoundSettlementService $settlementService,
    ): int {
        $config = $configProvider->get();
        $boot = $lineStateService->bootstrap($config);
        $count = $settlementService->settleStrategy($boot['strategy'], $config);
        $this->info('已结算订单数: '.$count);

        return self::SUCCESS;
    }
}
