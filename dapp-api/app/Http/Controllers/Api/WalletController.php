<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pm\PmCustodyTransferRequest;
use App\Models\Pm\PmCustodyWallet;
use App\Models\Pm\PmMember;
use App\Services\Pm\CustodyCipher;
use App\Services\Pm\CustodyTransferService;
use App\Services\Pm\EthSignature;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketTradingService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;
use kornrunner\Ethereum\Address;

class WalletController extends Controller
{
    use ApiResponseTrait;

    private function currentMember(Request $request): PmMember
    {
        /** @var PmMember $user */
        $user = $request->user();
        return $user;
    }

    /**
     * 导入私钥（托管）
     *
     * 入参：private_key（0x... 或 64 hex）
     */
    public function import(Request $request, CustodyCipher $cipher, GammaClient $gamma)
    {
        $member = $this->currentMember($request);

        $privateKey = trim((string) $request->input('private_key', ''));
        if ($privateKey === '') {
            return $this->error('private_key 必填');
        }
        if (str_starts_with(strtolower($privateKey), '0x')) {
            $privateKey = substr($privateKey, 2);
        }
        $privateKey = strtolower($privateKey);

        if (!ctype_xdigit($privateKey) || strlen($privateKey) !== 64) {
            return $this->error('private_key 格式不正确');
        }

        // 推导 signer 地址
        $addr = new Address($privateKey);
        $signerAddress = '0x' . $addr->get();
        $signerAddress = strtolower($signerAddress);

        // 要求导入私钥必须与登录地址一致（降低风险）
        if (strtolower($member->address) !== $signerAddress) {
            return $this->error('导入私钥地址与当前登录地址不一致');
        }

        // 解析 funder/proxyWallet（用于后续下单 maker/funder）
        // 这里允许 Gamma 查询失败：失败时先退化为 signer=funder，避免导入私钥被外部 profile 接口阻塞。
        $profile = [];
        $proxyWallet = null;
        $funder = $signerAddress;

        try {
            $profile = $gamma->getPublicProfile($signerAddress);
            $proxyWallet = $profile['proxyWallet'] ?? $profile['proxy_wallet'] ?? null;
            if (is_string($proxyWallet) && EthSignature::isAddress($proxyWallet)) {
                $funder = strtolower($proxyWallet);
            }
        } catch (\Throwable $e) {
            // ignore: 无法获取 profile 时，先使用 signer 作为 funder
        }

        $ciphertext = $cipher->encryptString('0x' . $privateKey);

        $wallet = PmCustodyWallet::updateOrCreate(
            [
                'member_id' => $member->id,
                'wallet_role' => PmCustodyWallet::ROLE_MASTER,
            ],
            [
                'parent_wallet_id' => null,
                'purpose' => 'polymarket_signer',
                'signer_address' => $signerAddress,
                'funder_address' => $funder,
                'private_key_ciphertext' => $ciphertext,
                'encryption_version' => 1,
                // 默认 EOA；如果未来识别到 Safe/Proxy，再改为 1/2
                'signature_type' => 0,
                'exchange_nonce' => '0',
                'status' => 1,
            ]
        );

        return $this->success('ok', [
            'wallet' => [
                'id' => $wallet->id,
                'signer_address' => $wallet->signer_address,
                'funder_address' => $wallet->funder_address,
                'signature_type' => $wallet->signature_type,
            ],
            'profile' => $profile,
        ]);
    }

    public function status(Request $request)
    {
        $member = $this->currentMember($request);

        $wallet = PmCustodyWallet::where('member_id', $member->id)
            ->where('wallet_role', PmCustodyWallet::ROLE_MASTER)
            ->first();

        return $this->success('ok', [
            'has_wallet' => (bool) $wallet,
            'wallet' => $wallet ? [
                'signer_address' => $wallet->signer_address,
                'funder_address' => $wallet->funder_address,
                'signature_type' => $wallet->signature_type,
                'exchange_nonce' => $wallet->exchange_nonce,
                'status' => $wallet->status,
                'created_at' => $wallet->created_at?->toDateTimeString(),
            ] : null,
        ]);
    }

    public function createSubWallet(Request $request, CustodyTransferService $service)
    {
        $member = $this->currentMember($request);
        $wallet = $service->createSubWallet($member, [
            'purpose' => $request->input('purpose', 'asset_holder'),
        ]);

        return $this->success('ok', [
            'wallet' => [
                'id' => $wallet->id,
                'wallet_role' => $wallet->wallet_role,
                'signer_address' => $wallet->signer_address,
                'funder_address' => $wallet->funder_address,
                'parent_wallet_id' => $wallet->parent_wallet_id,
                'purpose' => $wallet->purpose,
                'status' => $wallet->status,
            ],
        ]);
    }

    public function custodyStatus(Request $request, CustodyTransferService $service)
    {
        $member = $this->currentMember($request);
        return $this->success('ok', $service->custodyStatus($member));
    }

    public function prepareTransfer(Request $request, CustodyTransferService $service)
    {
        $member = $this->currentMember($request);
        $subWalletId = (int) $request->input('sub_wallet_id');
        $subWallet = PmCustodyWallet::where('member_id', $member->id)
            ->where('wallet_role', PmCustodyWallet::ROLE_SUB)
            ->find($subWalletId);

        if (!$subWallet) {
            return $this->error('子钱包不存在');
        }

        $result = $service->prepareErc20TransferAuthorization($subWallet, [
            'token_address' => (string) $request->input('token_address', ''),
            'to_address' => (string) $request->input('to_address', ''),
            'amount' => (string) $request->input('amount', ''),
            'chain_id' => (int) $request->input('chain_id', (int) config('pm.chain_id', 137)),
        ]);

        /** @var PmCustodyTransferRequest $transferRequest */
        $transferRequest = $result['request'];

        return $this->success('ok', [
            'request' => [
                'id' => $transferRequest->id,
                'status' => $transferRequest->status,
                'token_address' => $transferRequest->token_address,
                'from_address' => $transferRequest->from_address,
                'to_address' => $transferRequest->to_address,
                'amount' => $transferRequest->amount,
                'nonce' => $transferRequest->nonce,
                'deadline_at' => $transferRequest->deadline_at,
            ],
            'payload' => $result['payload'],
            'signature' => $result['signature'],
        ]);
    }

    public function submitTransfer(Request $request, CustodyTransferService $service)
    {
        $member = $this->currentMember($request);
        $transferRequest = PmCustodyTransferRequest::where('member_id', $member->id)
            ->find((int) $request->input('request_id'));

        if (!$transferRequest) {
            return $this->error('转账请求不存在');
        }

        $result = $service->executeSponsoredErc20Transfer($transferRequest);

        return $this->success('代付交易已提交', $result);
    }

    public function transferDetail(Request $request, int $id, CustodyTransferService $service)
    {
        $member = $this->currentMember($request);
        $transferRequest = PmCustodyTransferRequest::where('member_id', $member->id)->find($id);
        if (!$transferRequest) {
            return $this->error('转账请求不存在');
        }

        return $this->success('ok', $service->getTransferRequestStatus($transferRequest));
    }

    public function allowanceStatus(Request $request, PolymarketTradingService $trading)
    {
        $member = $this->currentMember($request);
        $wallet = PmCustodyWallet::with('apiCredentials')
            ->where('member_id', $member->id)
            ->where('wallet_role', PmCustodyWallet::ROLE_MASTER)
            ->first();

        if (!$wallet) {
            return $this->error('请先导入托管钱包');
        }

        $side = strtoupper((string) $request->input('side', PolymarketTradingService::SIDE_BUY));
        $tokenId = trim((string) $request->input('token_id', ''));

        $allowance = $side === PolymarketTradingService::SIDE_SELL
            ? $trading->getConditionalAllowanceStatus($wallet, $tokenId)
            : $trading->getAllowanceStatus($wallet);
        $readiness = $trading->getTradingReadiness($wallet, $side, $tokenId !== '' ? $tokenId : null);

        return $this->success('ok', [
            'has_wallet' => true,
            'wallet' => [
                'signer_address' => $wallet->signer_address,
                'funder_address' => $wallet->funder_address,
                'signature_type' => $wallet->signature_type,
                'exchange_nonce' => $wallet->exchange_nonce,
            ],
            'side' => $side,
            'allowance' => $allowance,
            'readiness' => $readiness,
        ]);
    }

    public function approve(Request $request, PolymarketTradingService $trading)
    {
        $member = $this->currentMember($request);
        $wallet = PmCustodyWallet::with('apiCredentials')
            ->where('member_id', $member->id)
            ->where('wallet_role', PmCustodyWallet::ROLE_MASTER)
            ->first();

        if (!$wallet) {
            return $this->error('请先导入托管钱包');
        }

        $side = strtoupper((string) $request->input('side', PolymarketTradingService::SIDE_BUY));
        $tokenId = trim((string) $request->input('token_id', ''));
        if ($side === PolymarketTradingService::SIDE_SELL && $tokenId === '') {
            return $this->error('SELL 授权需要 token_id');
        }

        $result = $trading->approveForSide($wallet, $side, $tokenId !== '' ? $tokenId : null);

        return $this->success(($result['already_approved'] ?? false) ? '当前已授权，无需重复操作' : '授权交易已提交', $result);
    }
}
