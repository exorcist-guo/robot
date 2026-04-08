<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCustodyWallet;
use App\Services\Pm\PolymarketTradingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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

        $a = $this->buildCurrentRoundSlugFromString('2026-04-07 18:49:03');
        echo $a;
        exit;
        $order = \App\Models\Pm\PmOrder::find(360);
        if (!$order) {
            $this->error('订单不存在');
            return self::FAILURE;
        }

        $service = app(\App\Services\Pm\PmOrderSettlementSyncService::class);

        // 获取远端订单数据
        $remote = $order->settlement_payload['remote_order'] ?? [];
        if (empty($remote)) {
            $this->error('没有远端订单数据');
            return self::FAILURE;
        }

        // 调用 buildSettlementSnapshot
        $snapshot = $service->buildSettlementSnapshot($order, $remote);

        $this->info('Settlement Snapshot:');
        $this->line('Is Settled: ' . ($snapshot['is_settled'] ? 'true' : 'false'));
        $this->line('Winning Outcome: ' . ($snapshot['winning_outcome'] ?? 'null'));
        $this->line('Is Win: ' . (isset($snapshot['is_win']) ? ($snapshot['is_win'] ? 'true' : 'false') : 'null'));
        $this->line('PNL USDC: ' . ($snapshot['pnl_usdc'] ?? 'null'));
        $this->line('Settlement Source: ' . ($snapshot['settlement_source'] ?? 'null'));


        exit;
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

    private function buildCurrentRoundSlugFromString(string $dateTimeString): string
    {
        $baseSlug = 'btc-updown-5m';
        $now = Carbon::parse($dateTimeString);
        $minutes = (int) $now->format('i');
        $targetMinutes = (int) (floor($minutes / 5) * 5);
        $timestamp = strtotime($now->format('Y-m-d H:').sprintf('%02d', $targetMinutes).':00');

        return $baseSlug.'-'.$timestamp;
    }
}
