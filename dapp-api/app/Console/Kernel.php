<?php

namespace App\Console;

use App\Console\Commands\BnbHealthCheckCommand;
use App\Console\Commands\PmApproveWalletCommand;
use App\Console\Commands\PmDebugOrderIntentCommand;
use App\Console\Commands\PmPollLeaderTradesCommand;
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
