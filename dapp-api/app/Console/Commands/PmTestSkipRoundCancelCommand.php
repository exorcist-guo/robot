<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCustodyWallet;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketTradingService;
use App\Services\Pm\SkipRoundCancelOrderService;
use App\Services\Pm\SkipRoundConfigProvider;
use App\Services\Pm\SkipRoundLineStateService;
use App\Services\Pm\SkipRoundMarketResolverService;
use App\Services\Pm\SkipRoundOrderbookService;
use App\Services\Pm\SkipRoundPredictService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Command;

class PmTestSkipRoundCancelCommand extends Command
{
    protected $signature = 'pm:test-skip-round-cancel {--size=0.1 : 下单 size，默认 0.1}';

    protected $description = '自动计算下一轮 market，挂一笔小额 BUY 限价单，再查询、撤单，并验证补市价单';

    public function handle(
        PolymarketTradingService $trading,
        SkipRoundCancelOrderService $cancelService,
        SkipRoundOrderbookService $orderbookService,
        SkipRoundConfigProvider $configProvider,
        SkipRoundLineStateService $lineStateService,
        SkipRoundPredictService $predictService,
        SkipRoundMarketResolverService $marketResolver,
        GammaClient $gammaClient,
    ): int {
        $config = $configProvider->get();
        $size = trim((string) $this->option('size'));
        if ($size === '' || preg_match('/^\d+(\.\d+)?$/', $size) !== 1) {
            $this->error('size 必须为正数');
            return self::FAILURE;
        }

        $wallet = PmCustodyWallet::with('apiCredentials')->where('member_id', (int) $config['member_id'])->first();
        if (!$wallet) {
            $this->error('wallet 不存在');
            return self::FAILURE;
        }

        try {
            $boot = $lineStateService->bootstrap($config);
            $strategy = $boot['strategy'];
            $prediction = $predictService->predict($config, now());
            if (($prediction['ok'] ?? false) !== true) {
                $this->error('预测未触发: ' . json_encode($prediction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return self::FAILURE;
            }

            $resolved = $marketResolver->resolveAndStore($strategy, $config, $prediction, $gammaClient);
            if (($resolved['ok'] ?? false) !== true) {
                $this->error('解析下一轮 market 失败: ' . json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return self::FAILURE;
            }

            $market = $resolved['market'];
            $predictedSide = (string) ($prediction['predicted_side'] ?? '');
            $tokenId = $predictedSide === 'up'
                ? (string) ($market['token_yes_id'] ?? '')
                : (string) ($market['token_no_id'] ?? '');
            $marketId = (string) ($market['market_id'] ?? '');
            $outcome = $predictedSide === 'up' ? 'Up' : 'Down';

            if ($tokenId === '' || $marketId === '') {
                $this->error('下一轮 market 缺少 token_id 或 market_id');
                return self::FAILURE;
            }

            $book = $trading->getOrderBook($tokenId);
            $level = $orderbookService->pickHighestAskLevelForBuy($book);
            if (!$level) {
                $this->error('未找到可用买盘价格');
                return self::FAILURE;
            }

            $price = '0.1';
            $this->info('准备挂单');
            $this->line(json_encode([
                'member_id' => (int) $config['member_id'],
                'strategy_key' => $config['strategy_key'] ?? null,
                'target_round_key' => $prediction['target_round_key'] ?? null,
                'market_id' => $marketId,
                'market_slug' => $market['slug'] ?? null,
                'token_id' => $tokenId,
                'predicted_side' => $predictedSide,
                'outcome' => $outcome,
                'price' => $price,
                'size' => $size,
                'selected_level' => $level,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $placed = $trading->placeOrder($wallet, [
                'token_id' => $tokenId,
                'market_id' => $marketId,
                'outcome' => $outcome,
                'side' => PolymarketTradingService::SIDE_BUY,
                'price' => $price,
                'size' => $size,
                'order_type' => 'GTC',
                'defer_exec' => true,
                'expiration' => '0',
            ]);

            $response = is_array($placed['response'] ?? null) ? $placed['response'] : [];
            $remoteOrderId = (string) ($response['id'] ?? $response['orderID'] ?? $response['orderId'] ?? '');
            if ($remoteOrderId === '') {
                $this->error('挂单成功但未拿到 remote order id');
                $this->line(json_encode($placed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return self::FAILURE;
            }

            $this->info('挂单成功');
            $this->line(json_encode([
                'remote_order_id' => $remoteOrderId,
                'request' => $placed['request'] ?? [],
                'response' => $response,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            sleep(2);

            try {
                $beforeCancel = $trading->getUserOrder($wallet, $remoteOrderId);
                $this->info('撤单前远端状态');
                $this->line(json_encode($beforeCancel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (\Throwable $e) {
                $this->warn('撤单前查询单笔订单失败：' . $e->getMessage());
            }

            $cancelResponse = $cancelService->cancel($wallet, $remoteOrderId);
            $this->info('撤单请求结果');
            $this->line(json_encode($cancelResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            sleep(2);

            $afterCancel = null;
            try {
                $afterCancel = $trading->getUserOrder($wallet, $remoteOrderId);
                $this->info('撤单后远端状态');
                $this->line(json_encode($afterCancel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (\Throwable $e) {
                $this->warn('撤单后查询单笔订单失败：' . $e->getMessage());
            }

            try {
                $orders = $trading->getUserOrders($wallet, [], 200, 0);
                $matched = [];
                foreach ($orders as $item) {
                    if ((string) ($item['id'] ?? '') === $remoteOrderId) {
                        $matched[] = $item;
                    }
                }
                $this->info('撤单后从用户订单列表反查');
                $this->line(json_encode($matched, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } catch (\Throwable $e) {
                $this->warn('撤单后查询用户订单列表失败：' . $e->getMessage());
            }

            $isCanceled = false;
            $canceled = is_array($cancelResponse['canceled'] ?? null) ? $cancelResponse['canceled'] : [];
            if (in_array($remoteOrderId, array_map('strval', $canceled), true)) {
                $isCanceled = true;
            }
            if (is_array($afterCancel) && strtoupper((string) ($afterCancel['status'] ?? '')) === 'CANCELED') {
                $isCanceled = true;
            }

            if (!$isCanceled) {
                $this->warn('未确认远端已撤销，跳过补市价单测试');
                return self::SUCCESS;
            }

            $remainingSize = BigDecimal::of($size)
                ->toScale(2, RoundingMode::DOWN)
                ->stripTrailingZeros()
                ->__toString();
            $marketPrice = $trading->getOrderBookMarketPrice(
                $tokenId,
                PolymarketTradingService::SIDE_BUY,
                '0',
                $remainingSize
            );
            $executionPrice = (string) ($marketPrice['price'] ?? '0');
            if ($executionPrice === '' || $executionPrice === '0') {
                $this->warn('未拿到可用市价，跳过补市价单测试');
                return self::SUCCESS;
            }

            $normalizedPrice = BigDecimal::of($executionPrice)->toScale(4, RoundingMode::DOWN)->stripTrailingZeros()->__toString();
            $normalizedSize = BigDecimal::of($remainingSize)
                ->toScale(2, RoundingMode::DOWN)
                ->stripTrailingZeros()
                ->__toString();
            $normalizedNotional = BigDecimal::of($normalizedPrice)
                ->multipliedBy($normalizedSize)
                ->toScale(6, RoundingMode::DOWN)
                ->__toString();

            $this->info('准备补市价单');
            $this->line(json_encode([
                'market_price' => $marketPrice,
                'normalized_price' => $normalizedPrice,
                'normalized_size' => $normalizedSize,
                'normalized_notional' => $normalizedNotional,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $marketBuyPlaced = $trading->placeOrder($wallet, [
                'token_id' => $tokenId,
                'market_id' => $marketId,
                'outcome' => $outcome,
                'side' => PolymarketTradingService::SIDE_BUY,
                'price' => $normalizedPrice,
                'size' => $normalizedSize,
                'order_type' => 'FOK',
                'defer_exec' => false,
                'expiration' => '0',
            ]);

            $marketBuyResponse = is_array($marketBuyPlaced['response'] ?? null) ? $marketBuyPlaced['response'] : [];
            $this->info('补市价单结果');
            $this->line(json_encode([
                'request' => $marketBuyPlaced['request'] ?? [],
                'response' => $marketBuyResponse,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('测试失败：' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
