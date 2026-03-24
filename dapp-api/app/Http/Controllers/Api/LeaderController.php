<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pm\PmLeader;
use App\Services\Pm\EthSignature;
use App\Services\Pm\GammaClient;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class LeaderController extends Controller
{
    use ApiResponseTrait;

    public function resolve(Request $request, GammaClient $gamma)
    {
        $address = (string) $request->input('address', '');
        $address = EthSignature::normalizeAddress($address);
        if (!EthSignature::isAddress($address)) {
            return $this->error('无效的钱包地址');
        }

        $profile = $gamma->getPublicProfile($address);
        $proxyWallet = $profile['proxyWallet'] ?? $profile['proxy_wallet'] ?? null;
        if (!is_string($proxyWallet) || !EthSignature::isAddress($proxyWallet)) {
            return $this->error('无法解析 proxyWallet');
        }
        $proxyWallet = strtolower($proxyWallet);

        $leader = PmLeader::firstOrCreate(
            ['proxy_wallet' => $proxyWallet],
            [
                'input_address' => strtolower($address),
                'display_name' => $profile['username'] ?? $profile['name'] ?? null,
                'avatar_url' => $profile['avatarUrl'] ?? $profile['avatar_url'] ?? null,
                'status' => 1,
            ]
        );

        return $this->success('ok', [
            'leader' => [
                'id' => $leader->id,
                'input_address' => $leader->input_address,
                'proxy_wallet' => $leader->proxy_wallet,
                'display_name' => $leader->display_name,
                'avatar_url' => $leader->avatar_url,
                'status' => $leader->status,
            ],
            'profile' => $profile,
        ]);
    }

    public function index(Request $request)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $page = max(1, (int) $request->query('page', 1));
        $limit = min(50, max(1, (int) $request->query('limit', 20)));

        $query = PmLeader::query();
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('proxy_wallet', 'like', "%{$keyword}%")
                    ->orWhere('input_address', 'like', "%{$keyword}%")
                    ->orWhere('display_name', 'like', "%{$keyword}%");
            });
        }

        $count = (clone $query)->count();
        $list = $query->orderByDesc('id')
            ->forPage($page, $limit)
            ->get()
            ->map(fn (PmLeader $l) => [
                'id' => $l->id,
                'input_address' => $l->input_address,
                'proxy_wallet' => $l->proxy_wallet,
                'display_name' => $l->display_name,
                'avatar_url' => $l->avatar_url,
                'status' => $l->status,
            ]);

        return $this->success('ok', [
            'count' => $count,
            'list' => $list,
        ]);
    }
}
