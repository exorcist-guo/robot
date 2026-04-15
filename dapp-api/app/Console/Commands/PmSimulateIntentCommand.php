<?php

namespace App\Console\Commands;

use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\IntentExecutionPrecheckService;
use Illuminate\Console\Command;

class PmSimulateIntentCommand extends Command
{
    protected $signature = 'pm:simulate-intent {intent_id : pm_order_intents.id}';

    protected $description = '对单个 intent 做 dry-run 模拟，不真实下单';

    public function handle(IntentExecutionPrecheckService $precheckService): int
    {
        $intent = PmOrderIntent::with(['copyTask', 'member.custodyWallet.apiCredentials', 'leaderTrade'])->find((int) $this->argument('intent_id'));
        if (!$intent) {
            $this->error('intent 不存在');
            return self::FAILURE;
        }

        $wallet = $intent->member?->custodyWallet;
        if (!$wallet) {
            $this->error('intent 对应钱包不存在');
            return self::FAILURE;
        }

        $result = $precheckService->evaluate($intent, $wallet, $intent->copyTask);
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
