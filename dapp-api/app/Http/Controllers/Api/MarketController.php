<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pm\PmCustodyWallet;
use App\Models\Pm\PmMember;
use App\Services\Pm\EthSignature;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketDataClient;
use App\Services\Pm\PolymarketTradingService;
use App\Traits\ApiResponseTrait;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    use ApiResponseTrait;

    public function resolve(Request $request, GammaClient $gamma)
    {
        $market = $gamma->resolveTailSweepMarket((string) $request->input('input', ''));
        if (!$market) {
            return $this->error('未找到对应市场');
        }

        return $this->success('ok', ['market' => $market]);
    }

    public function publicProfile(Request $request, GammaClient $gamma)
    {
        $address = $this->resolveAddress($request, 'address');
        if ($address === null) {
            return $this->error('无效的钱包地址');
        }

        try {
            $profile = $gamma->getPublicProfile($address);
        } catch (\Throwable) {
            return $this->error('获取资料失败');
        }

        return $this->success('ok', [
            'address' => $address,
            'profile' => $profile,
        ]);
    }

    public function activity(Request $request, PolymarketDataClient $dataClient)
    {
        $user = $this->resolveAddress($request, 'user') ?? $this->resolveAddress($request, 'address');
        if ($user === null) {
            return $this->error('无效的钱包地址');
        }

        $limit = min(100, max(1, (int) $request->query('limit', 30)));
        $offset = max(0, (int) $request->query('offset', 0));

        try {
            $list = $dataClient->getActivityByUser($user, $limit, $offset);
        } catch (\Throwable) {
            return $this->error('获取活动记录失败');
        }

        return $this->success('ok', [
            'user' => $user,
            'limit' => $limit,
            'offset' => $offset,
            'list' => $list,
        ]);
    }

    public function closedPositions(Request $request, PolymarketDataClient $dataClient)
    {
        $user = $this->resolveAddress($request, 'user') ?? $this->resolveAddress($request, 'address');
        if ($user === null) {
            return $this->error('无效的钱包地址');
        }

        $limit = min(100, max(1, (int) $request->query('limit', 30)));
        $offset = max(0, (int) $request->query('offset', 0));

        try {
            $list = $dataClient->getClosedPositionsByUser($user, $limit, $offset);
        } catch (\Throwable) {
            return $this->error('获取已平仓记录失败');
        }

        return $this->success('ok', [
            'user' => $user,
            'limit' => $limit,
            'offset' => $offset,
            'list' => $list,
        ]);
    }

    public function userPnl(Request $request, PolymarketDataClient $dataClient)
    {
        $userAddress = $this->resolveAddress($request, 'user_address') ?? $this->resolveAddress($request, 'address');
        if ($userAddress === null) {
            return $this->error('无效的钱包地址');
        }

        $interval = trim((string) $request->query('interval', '1m'));
        if ($interval === '') {
            $interval = '1m';
        }

        $fidelity = trim((string) $request->query('fidelity', '18h'));
        if ($fidelity === '') {
            $fidelity = '18h';
        }

        try {
            $pnl = $dataClient->getUserPnl($userAddress, $interval, $fidelity);
        } catch (\Throwable) {
            return $this->error('获取用户盈亏失败');
        }

        return $this->success('ok', [
            'user_address' => $userAddress,
            'interval' => $interval,
            'fidelity' => $fidelity,
            'pnl' => $pnl,
        ]);
    }

    public function userStats(Request $request, PolymarketDataClient $dataClient)
    {
        $proxyAddress = $this->resolveAddress($request, 'proxyAddress') ?? $this->resolveAddress($request, 'address');
        if ($proxyAddress === null) {
            return $this->error('无效的钱包地址');
        }

        try {
            $stats = $dataClient->getUserStats($proxyAddress);
        } catch (\Throwable) {
            return $this->error('获取用户统计失败');
        }

        return $this->success('ok', [
            'proxyAddress' => $proxyAddress,
            'stats' => $stats,
        ]);
    }

    public function userValue(Request $request, PolymarketDataClient $dataClient)
    {
        $user = $this->resolveAddress($request, 'user') ?? $this->resolveAddress($request, 'address');
        if ($user === null) {
            return $this->error('无效的钱包地址');
        }

        try {
            $value = $dataClient->getUserValue($user);
        } catch (\Throwable) {
            return $this->error('获取用户价值失败');
        }

        return $this->success('ok', [
            'user' => $user,
            'value' => $value,
        ]);
    }

    public function leaderboard(Request $request, PolymarketDataClient $dataClient)
    {
        $allowedCategories = ['OVERALL', 'POLITICS', 'SPORTS', 'CRYPTO', 'CULTURE', 'MENTIONS', 'WEATHER', 'ECONOMICS', 'TECH', 'FINANCE'];
        $allowedTimePeriods = ['DAY', 'WEEK', 'MONTH', 'ALL'];
        $allowedOrderBy = ['PNL', 'VOL'];

        $category = strtoupper(trim((string) $request->query('category', 'OVERALL')));
        $timePeriod = strtoupper(trim((string) $request->query('timePeriod', 'DAY')));
        $orderBy = strtoupper(trim((string) $request->query('orderBy', 'PNL')));
        $limit = min(50, max(1, (int) $request->query('limit', 25)));
        $offset = min(1000, max(0, (int) $request->query('offset', 0)));
        $user = $this->resolveAddress($request, 'user');
        $userName = trim((string) $request->query('userName', ''));

        if (!in_array($category, $allowedCategories, true)) {
            return $this->error('无效的分类参数');
        }
        if (!in_array($timePeriod, $allowedTimePeriods, true)) {
            return $this->error('无效的时间参数');
        }
        if (!in_array($orderBy, $allowedOrderBy, true)) {
            return $this->error('无效的排序参数');
        }
        if ($request->query('user') !== null && $user === null) {
            return $this->error('无效的钱包地址');
        }

        $params = [
            'category' => $category,
            'timePeriod' => $timePeriod,
            'orderBy' => $orderBy,
            'limit' => $limit,
            'offset' => $offset,
        ];
        if ($user !== null) {
            $params['user'] = $user;
        }
        if ($userName !== '') {
            $params['userName'] = $userName;
        }

        try {
            $list = $dataClient->getLeaderboardV1($params);
            $normalized = array_map(
                fn (array $entry) => $dataClient->normalizeLeaderboardEntryV1($entry, $timePeriod, $orderBy),
                $list
            );
        } catch (\Throwable) {
            return $this->error('获取排行榜失败');
        }

        return $this->success('ok', [
            'category' => $category,
            'timePeriod' => $timePeriod,
            'orderBy' => $orderBy,
            'limit' => $limit,
            'offset' => $offset,
            'user' => $user,
            'userName' => $userName,
            'list' => $normalized,
        ]);
    }

    public function sellPosition(Request $request, PolymarketTradingService $trading)
    {
        /** @var PmMember $member */
        $member = $request->user();
        $wallet = PmCustodyWallet::with('apiCredentials')
            ->where('member_id', $member->id)
            ->where('wallet_role', PmCustodyWallet::ROLE_MASTER)
            ->first();

        if (!$wallet) {
            return $this->error('PM 托管钱包不存在，请重新登录后重试');
        }

        $tokenId = trim((string) $request->input('token_id', ''));
        if ($tokenId === '' || preg_match('/^\d+$/', $tokenId) !== 1) {
            return $this->error('无效的持仓 token');
        }

        try {
            $book = $trading->getOrderBook($tokenId);
            $quote = $trading->getOrderBookMarketPrice($tokenId, PolymarketTradingService::SIDE_SELL, '0', null, $book);
            $executionPrice = (string) ($quote['price'] ?? '0');
            if (bccomp($executionPrice, '0', 8) <= 0) {
                return $this->error('当前没有可卖出的买盘深度');
            }

            $readiness = $trading->getTradingReadiness($wallet, PolymarketTradingService::SIDE_SELL, $tokenId);
            if (($readiness['failure_code'] ?? null) === 'insufficient_position_allowance') {
                $approveResult = $trading->approveForSide($wallet, PolymarketTradingService::SIDE_SELL, $tokenId);
                return $this->success('卖出授权已提交，请稍后重试卖出', [
                    'status' => 'approval_submitted',
                    'approval' => $approveResult,
                    'readiness' => $readiness,
                ]);
            }

            if (($readiness['is_ready'] ?? false) !== true) {
                return $this->error($this->mapSellReadinessMessage((string) ($readiness['failure_code'] ?? 'trade_not_ready')), [
                    'readiness' => $readiness,
                ]);
            }

            $balanceUnits = (string) ($readiness['balance'] ?? '0');
            if (preg_match('/^\d+$/', $balanceUnits) !== 1 || bccomp($balanceUnits, '0', 0) <= 0) {
                return $this->error('当前没有可卖出的持仓');
            }

            $size = BigDecimal::of($balanceUnits)->dividedBy('1000000', 2, RoundingMode::DOWN);
            $consumableSize = (string) ($quote['consumable_size'] ?? '0');
            if (preg_match('/^\d+(\.\d+)?$/', $consumableSize) === 1 && bccomp($consumableSize, '0', 8) > 0 && $size->isGreaterThan(BigDecimal::of($consumableSize))) {
                $size = BigDecimal::of($consumableSize);
            }

            if ($size->isLessThanOrEqualTo(BigDecimal::zero())) {
                return $this->error('当前没有可卖出的持仓');
            }

            $normalizedSize = $size->stripTrailingZeros()->__toString();
            $normalizedPrice = BigDecimal::of($executionPrice)->toScale(4, RoundingMode::DOWN)->stripTrailingZeros()->__toString();
            $order = $trading->placeOrder($wallet, [
                'token_id' => $tokenId,
                'market_id' => (string) $request->input('market_id', ''),
                'outcome' => (string) $request->input('outcome', ''),
                'side' => PolymarketTradingService::SIDE_SELL,
                'price' => $normalizedPrice,
                'size' => $normalizedSize,
                'order_type' => 'GTC',
                'defer_exec' => false,
                'expiration' => '0',
            ]);
        } catch (\Throwable $e) {
            return $this->error('卖出失败: ' . $e->getMessage());
        }

        return $this->success('卖出订单已提交', [
            'status' => 'submitted',
            'token_id' => $tokenId,
            'price' => $normalizedPrice,
            'size' => $normalizedSize,
            'quote' => $quote,
            'order' => $order,
        ]);
    }

    public function positions(Request $request, PolymarketDataClient $dataClient)
    {
        $user = $this->resolveAddress($request, 'user') ?? $this->resolveAddress($request, 'address');
        if ($user === null) {
            return $this->error('无效的钱包地址');
        }

        $limit = min(100, max(1, (int) $request->query('limit', 100)));
        $offset = max(0, (int) $request->query('offset', 0));

        try {
            $list = $dataClient->getUserPositions($user, $limit, $offset);
        } catch (\Throwable) {
            return $this->error('获取持仓失败');
        }

        return $this->success('ok', [
            'user' => $user,
            'limit' => $limit,
            'offset' => $offset,
            'list' => $list,
        ]);
    }

    private function mapSellReadinessMessage(string $reason): string
    {
        return match ($reason) {
            'insufficient_position_balance' => '当前没有可卖出的持仓',
            'insufficient_position_allowance' => '卖出授权不足，请先完成授权',
            'missing_token_id' => '无效的持仓 token',
            default => '当前持仓暂不可卖出',
        };
    }

    private function resolveAddress(Request $request, string $key): ?string
    {
        $address = EthSignature::normalizeAddress((string) $request->query($key, ''));

        return EthSignature::isAddress($address) ? $address : null;
    }
}
