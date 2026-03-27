<?php

namespace App\Console\Commands;

use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\PolymarketTradingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Command;

class PmDebugOrderIntentCommand extends Command
{
    protected $signature = 'pm:debug-order-intent {intent_id : pm_order_intents.id}';

    protected $description = '调试单条跟单意图的下单 payload';

    public function handle(PolymarketTradingService $trading): int
    {
        $intentId = (int) $this->argument('intent_id');
        $intent = PmOrderIntent::with(['member.custodyWallet.apiCredentials', 'leaderTrade'])->find($intentId);

        if (!$intent) {
            $this->error('intent 不存在');
            return self::FAILURE;
        }

        $wallet = $intent->member?->custodyWallet;
        if (!$wallet) {
            $this->error('wallet 不存在');
            return self::FAILURE;
        }

        $side = strtoupper((string) $intent->side);
        $leaderPrice = (string) ($intent->leader_price ?: '0');
        $bestPrice = $trading->getOrderBookBestPrice((string) $intent->token_id, $side);
        $executionPrice = (string) ($bestPrice['price'] ?? '0');
        if (bccomp($executionPrice, '0', 8) <= 0) {
            $this->error('无法获取最新盘口价');
            return self::FAILURE;
        }

        $usdc = BigDecimal::of((string) $intent->clamped_usdc)
            ->dividedBy('1000000', 6, RoundingMode::DOWN);
        $sizeScale = $side === PolymarketTradingService::SIDE_BUY ? 2 : 4;
        $size = $usdc->dividedBy($executionPrice, $sizeScale, RoundingMode::DOWN)->stripTrailingZeros()->__toString();

        $payload = [
            'token_id' => (string) $intent->token_id,
            'market_id' => (string) ($intent->leaderTrade?->market_id ?? ''),
            'outcome' => (string) ($intent->leaderTrade?->raw['outcome'] ?? ''),
            'side' => $side,
            'price' => BigDecimal::of($executionPrice)->toScale(4, RoundingMode::DOWN)->stripTrailingZeros()->__toString(),
            'size' => $size,
            'order_type' => 'GTC',
            'defer_exec' => false,
            'expiration' => '0',
        ];

        $this->info('intent: ' . $intent->id);
        $this->line('leader_price: ' . $leaderPrice);
        $this->line('execution_price: ' . $executionPrice);
        $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE));

        try {
            $result = $trading->placeOrder($wallet, $payload);
            $this->info('下单成功');
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('下单失败: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
