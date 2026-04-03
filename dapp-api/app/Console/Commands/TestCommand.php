<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCustodyWallet;
use App\Services\Pm\PolymarketTradingService;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test {--poly_order_id=0x313a085ba0fbde967f6f587ab83449b843568df49a4ceba772f22d4100d6626c}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试 Polymarket CLOB 集成';

    /**
     * Execute the console command.
     */
    public function handle(PolymarketTradingService $trading)
    {
        $wallet = PmCustodyWallet::query()
            ->with('apiCredentials')
            ->where('status', PmCustodyWallet::STATUS_ENABLED)
            ->orderByDesc('id')
            ->first();

        if (!$wallet) {
            $this->error('未找到可用托管钱包');
            return self::FAILURE;
        }

        $credRecord = $wallet->apiCredentials ?: $trading->ensureApiCredentials($wallet);
        $creds = $trading->decodeApiCredentials($credRecord);
        $polyOrderId = trim((string) $this->option('poly_order_id'));

        $payload = [
            'wallet_id' => $wallet->id,
            'member_id' => $wallet->member_id,
            'trading_address' => $wallet->tradingAddress(),
            'api_key_prefix' => substr($creds->apiKey, 0, 12),
            'orders' => $trading->getUserOrders($wallet, [], 20, 0),
        ];

        if ($polyOrderId !== '') {
            try {
                $payload['poly_order_id'] = $polyOrderId;
                $payload['order_by_id'] = $trading->getUserOrder($wallet, $polyOrderId);
            } catch (\Throwable $e) {
                $payload['poly_order_id'] = $polyOrderId;
                $payload['order_by_id_error'] = $e->getMessage();
            }
        }

        $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
