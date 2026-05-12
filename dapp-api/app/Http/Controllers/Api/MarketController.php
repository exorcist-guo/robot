<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pm\EthSignature;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketDataClient;
use App\Traits\ApiResponseTrait;
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

    private function resolveAddress(Request $request, string $key): ?string
    {
        $address = EthSignature::normalizeAddress((string) $request->query($key, ''));

        return EthSignature::isAddress($address) ? $address : null;
    }
}
