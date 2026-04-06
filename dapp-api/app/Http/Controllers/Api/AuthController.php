<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pm\PmAuthNonce;
use App\Models\Pm\PmMember;
use App\Services\Pm\CustodyTransferService;
use App\Services\Pm\EthSignature;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ApiResponseTrait;

    public function nonce(Request $request)
    {
        $address = (string) $request->input('address', '');
        $address = EthSignature::normalizeAddress($address);

        if (!EthSignature::isAddress($address)) {
            return $this->error('无效的钱包地址');
        }

        $nonce = Str::random(32);
        $ttl = (int) config('pm.login_nonce_ttl_seconds', 300);

        $ua = (string) $request->userAgent();

        PmAuthNonce::create([
            'address' => $address,
            'nonce' => $nonce,
            'expires_at' => now()->addSeconds($ttl),
            'ip' => $request->ip(),
            'ua_hash' => $ua ? hash('sha256', $ua) : null,
        ]);

        $appName = (string) config('app.name', 'robot');
        $message = implode("\n", [
            $appName . ' 登录验证',
            '地址: ' . $address,
            'Nonce: ' . $nonce,
        ]);

        return $this->success('ok', [
            'address' => $address,
            'nonce' => $nonce,
            'message' => $message,
            'expires_in' => $ttl,
        ]);
    }

    public function login(Request $request, CustodyTransferService $custodyTransferService)
    {
        $address = (string) $request->input('address', '');
        $signature = (string) $request->input('signature', '');
        $nonce = (string) $request->input('nonce', '');

        $address = EthSignature::normalizeAddress($address);

        if (!EthSignature::isAddress($address)) {
            return $this->error('无效的钱包地址');
        }
        if ($nonce === '') {
            return $this->error('缺少 nonce');
        }
        if ($signature === '') {
            return $this->error('缺少 signature');
        }

        $record = PmAuthNonce::where('address', $address)
            ->where('nonce', $nonce)
            ->whereNull('used_at')
            ->first();

        if (!$record) {
            return $this->error('nonce 不存在或已使用');
        }
        if ($record->expires_at->isPast()) {
            return $this->error('nonce 已过期');
        }

        $appName = (string) config('app.name', 'robot');
        // 登录时必须使用与 /auth/nonce 返回完全一致的 message
        $message = implode("\n", [
            $appName . ' 登录验证',
            '地址: ' . $address,
            'Nonce: ' . $nonce,
        ]);

        try {
            $recovered = EthSignature::recoverAddress($message, $signature);
        } catch (\Throwable $e) {
            return $this->error('签名校验失败');
        }

        if (strtolower($recovered) !== strtolower($address)) {
            return $this->error('签名地址不匹配');
        }

        $record->used_at = now();
        $record->save();

        $member = PmMember::firstOrCreate(
            ['address' => strtolower($address)],
            [
                'nickname' => null,
                'avatar_url' => null,
                'status' => 1,
                'path' => '/',
                'deep' => 0,
                'inviter_id' => null,
            ]
        );

        $member->last_login_at = now();
        $member->save();
        $wallet = $custodyTransferService->getOrCreateMasterWallet($member);

        $token = $member->createToken('h5')->plainTextToken;

        return $this->success('ok', [
            'token' => $token,
            'member' => [
                'id' => $member->id,
                'address' => $member->address,
                'nickname' => $member->nickname,
                'avatar_url' => $member->avatar_url,
            ],
            'wallet' => [
                'address' => $wallet->address,
                'signer_address' => $wallet->signer_address,
                'funder_address' => $wallet->funder_address,
                'signature_type' => $wallet->signature_type,
                'status' => $wallet->status,
            ],
        ]);
    }
}
