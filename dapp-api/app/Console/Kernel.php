<?php

namespace App\Console;

use App\Console\Commands\BnbHealthCheckCommand;
use App\Console\Commands\PmApproveWalletCommand;
use App\Console\Commands\PmDebugOrderIntentCommand;
use App\Console\Commands\PmPollLeaderTradesCommand;
use App\Console\Commands\PmLossOrderAvgPriceCommand;
use App\Console\Commands\PmMarketInfoDaemonCommand;
use App\Console\Commands\PmSyncOrderSettlementCommand;
use App\Console\Commands\PmTailSweepPriceDaemonCommand;
use App\Console\Commands\PmValidateSetupCommand;
use App\Console\Commands\TestCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        TestCommand::class,
        BnbHealthCheckCommand::class,
        PmApproveWalletCommand::class,
        PmDebugOrderIntentCommand::class,
        PmLossOrderAvgPriceCommand::class,
        PmPollLeaderTradesCommand::class,
        PmMarketInfoDaemonCommand::class,
        PmSyncOrderSettlementCommand::class,
        PmTailSweepPriceDaemonCommand::class,
        PmValidateSetupCommand::class,
    ];
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 每分钟验证已兑奖订单的链上状态
        $schedule->command('pm:verify-claim-tx --all-claimed')
        ->everyMinute()
        ->withoutOverlapping();
        // 每分钟同步未结算订单并加入兑奖队列
        $schedule->command('pm:sync-order-settlement --only-unsettled --queue-claim')
        ->everyMinute()
        ->withoutOverlapping();

        // 每小时扫描所有钱包并自动领取奖励（1小时以上的订单）
        $schedule->command('pm:claim-position --scan-all --min-age=3600')
        ->hourly()
        ->withoutOverlapping();

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
