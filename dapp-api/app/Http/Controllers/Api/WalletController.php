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
     * PM 托管钱包已改为登录自动创建。
     */
    public function import(Request $request)
    {
        return $this->error('PM 托管钱包已改为登录自动创建，无需再导入私钥');
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
                'address' => $wallet->address,
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
                'address' => $wallet->address,
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
            return $this->error('PM 托管钱包不存在，请重新登录后重试');
        }

        $side = strtoupper((string) $request->input('side', PolymarketTradingService::SIDE_BUY));
        $tokenId = trim((string) $request->input('token_id', ''));
        $refresh = $request->boolean('refresh');

        $allowance = $side === PolymarketTradingService::SIDE_SELL
            ? $trading->getConditionalAllowanceStatus($wallet, $tokenId, $refresh)
            : $trading->getAllowanceStatus($wallet, $refresh);
        $readiness = $trading->getTradingReadiness($wallet, $side, $tokenId !== '' ? $tokenId : null);

        return $this->success('ok', [
            'has_wallet' => true,
            'wallet' => [
                'address' => $wallet->address,
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
            return $this->error('PM 托管钱包不存在，请重新登录后重试');
        }

        $side = strtoupper((string) $request->input('side', PolymarketTradingService::SIDE_BUY));
        $tokenId = trim((string) $request->input('token_id', ''));
        if ($side === PolymarketTradingService::SIDE_SELL && $tokenId === '') {
            return $this->error('SELL 授权需要 token_id');
        }

        try {
            $result = $trading->approveForSide($wallet, $side, $tokenId !== '' ? $tokenId : null);
        } catch (\Throwable $e) {
            return $this->error('授权失败: ' . $e->getMessage());
        }

        return $this->success(($result['already_approved'] ?? false) ? '当前已授权，无需重复操作' : '授权交易已提交', $result);
    }
}
