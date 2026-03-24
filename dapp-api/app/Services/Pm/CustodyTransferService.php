<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyTransferRequest;
use App\Models\Pm\PmCustodyWallet;
use App\Models\Pm\PmMember;
use EthTool\Credential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use kornrunner\Keccak;

class CustodyTransferService
{
    public function __construct(
        private readonly CustodyCipher $cipher,
        private readonly PolygonRpcService $rpc,
    ) {}

    public function createSubWallet(PmMember $member, array $params = []): PmCustodyWallet
    {
        $masterWallet = $this->getOrCreateMasterWallet($member);
        $existing = PmCustodyWallet::where('member_id', $member->id)
            ->where('wallet_role', PmCustodyWallet::ROLE_SUB)
            ->where('purpose', (string) ($params['purpose'] ?? 'asset_holder'))
            ->first();
        if ($existing) {
            return $existing;
        }

        $credential = Credential::new();
        $privateKey = '0x' . ltrim($credential->getPrivateKey(), '0x');
        $address = strtolower($credential->getAddress());

        return PmCustodyWallet::create([
            'member_id' => $member->id,
            'wallet_role' => PmCustodyWallet::ROLE_SUB,
            'parent_wallet_id' => $masterWallet->id,
            'purpose' => (string) ($params['purpose'] ?? 'asset_holder'),
            'signer_address' => $address,
            'funder_address' => $masterWallet->signer_address,
            'private_key_ciphertext' => $this->cipher->encryptString($privateKey),
            'encryption_version' => 1,
            'signature_type' => 0,
            'exchange_nonce' => '0',
            'status' => PmCustodyWallet::STATUS_ENABLED,
        ]);
    }

    public function getOrCreateMasterWallet(PmMember $member): PmCustodyWallet
    {
        $wallet = PmCustodyWallet::where('member_id', $member->id)
            ->where('wallet_role', PmCustodyWallet::ROLE_MASTER)
            ->first();

        if ($wallet) {
            return $wallet;
        }

        $privateKey = trim((string) config('pm.sponsor_private_key'));
        if ($privateKey === '') {
            throw new \RuntimeException('PM_SPONSOR_PRIVATE_KEY 未配置');
        }

        $privateKey = $this->normalizePrivateKey($privateKey);
        $credential = Credential::fromKey(ltrim($privateKey, '0x'));
        $address = strtolower($credential->getAddress());

        return PmCustodyWallet::create([
            'member_id' => $member->id,
            'wallet_role' => PmCustodyWallet::ROLE_MASTER,
            'parent_wallet_id' => null,
            'purpose' => 'gas_sponsor',
            'signer_address' => $address,
            'funder_address' => $address,
            'private_key_ciphertext' => $this->cipher->encryptString($privateKey),
            'encryption_version' => 1,
            'signature_type' => 0,
            'exchange_nonce' => '0',
            'status' => PmCustodyWallet::STATUS_ENABLED,
        ]);
    }

    public function custodyStatus(PmMember $member): array
    {
        $masterWallet = PmCustodyWallet::where('member_id', $member->id)
            ->where('wallet_role', PmCustodyWallet::ROLE_MASTER)
            ->first();

        $subWallets = PmCustodyWallet::where('member_id', $member->id)
            ->where('wallet_role', PmCustodyWallet::ROLE_SUB)
            ->orderBy('id')
            ->get();

        return [
            'master_wallet' => $masterWallet ? $this->walletData($masterWallet) : null,
            'sub_wallets' => $subWallets->map(fn (PmCustodyWallet $wallet) => $this->walletData($wallet))->values()->all(),
        ];
    }

    public function prepareErc20TransferAuthorization(PmCustodyWallet $subWallet, array $params): array
    {
        if (!$subWallet->isSub()) {
            throw new \RuntimeException('仅子钱包允许发起代付转账');
        }

        $masterWallet = $subWallet->parentWallet;
        if (!$masterWallet) {
            throw new \RuntimeException('子钱包未绑定主钱包');
        }

        $token = EthSignature::normalizeAddress((string) ($params['token_address'] ?? ''));
        $to = EthSignature::normalizeAddress((string) ($params['to_address'] ?? ''));
        $amount = trim((string) ($params['amount'] ?? ''));
        $chainId = (int) ($params['chain_id'] ?? config('pm.chain_id', 137));

        if (!EthSignature::isAddress($token)) {
            throw new \InvalidArgumentException('token_address 格式不正确');
        }
        if (!EthSignature::isAddress($to)) {
            throw new \InvalidArgumentException('to_address 格式不正确');
        }
        if (!preg_match('/^\d+$/', $amount) || bccomp($amount, '0', 0) <= 0) {
            throw new \InvalidArgumentException('amount 必须为正整数最小单位');
        }

        $this->assertAllowedToken($token);
        $this->assertAllowedAmount($amount);

        return DB::transaction(function () use ($subWallet, $masterWallet, $token, $to, $amount, $chainId) {
            $nextNonce = (string) (PmCustodyTransferRequest::where('sub_wallet_id', $subWallet->id)->lockForUpdate()->count() + 1);
            $deadlineAt = time() + (int) config('pm.sponsored_transfer_deadline_ttl_seconds', 600);

            $payload = [
                'action' => 'erc20_transfer',
                'subWallet' => strtolower($subWallet->signer_address),
                'masterWallet' => strtolower($masterWallet->signer_address),
                'token' => $token,
                'to' => $to,
                'amount' => $amount,
                'nonce' => $nextNonce,
                'deadline' => (string) $deadlineAt,
                'chainId' => (string) $chainId,
                'verifyingContract' => strtolower((string) config('pm.sponsored_transfer_executor', '')),
            ];

            $hash = $this->payloadHash($payload);
            $signature = $this->signPayload($subWallet, $payload);

            $request = PmCustodyTransferRequest::create([
                'member_id' => $subWallet->member_id,
                'sub_wallet_id' => $subWallet->id,
                'master_wallet_id' => $masterWallet->id,
                'chain_id' => $chainId,
                'token_address' => $token,
                'from_address' => strtolower($subWallet->signer_address),
                'to_address' => $to,
                'amount' => $amount,
                'nonce' => $nextNonce,
                'deadline_at' => $deadlineAt,
                'action' => 'erc20_transfer',
                'signature_payload_hash' => $hash,
                'signature' => $signature,
                'status' => PmCustodyTransferRequest::STATUS_SIGNED,
                'raw_request_json' => $payload,
            ]);

            return [
                'request' => $request,
                'payload' => $payload,
                'signature' => $signature,
            ];
        });
    }

    public function executeSponsoredErc20Transfer(PmCustodyTransferRequest $request): array
    {
        if ($request->status !== PmCustodyTransferRequest::STATUS_SIGNED) {
            throw new \RuntimeException('当前请求状态不允许提交');
        }
        if ($request->isExpired()) {
            $request->update([
                'status' => PmCustodyTransferRequest::STATUS_EXPIRED,
                'failure_reason' => 'authorization expired',
            ]);
            throw new \RuntimeException('授权已过期');
        }

        $subWallet = $request->subWallet;
        $masterWallet = $request->masterWallet;
        if (!$subWallet || !$masterWallet) {
            throw new \RuntimeException('请求关联钱包不存在');
        }

        $payload = is_array($request->raw_request_json) ? $request->raw_request_json : [];
        if (!$this->verifyErc20TransferAuthorization($payload, (string) $request->signature, strtolower($subWallet->signer_address))) {
            throw new \RuntimeException('子钱包签名校验失败');
        }

        $masterPrivateKey = $this->cipher->decryptString($masterWallet->private_key_ciphertext);
        $credential = Credential::fromKey(ltrim($masterPrivateKey, '0x'));
        $from = strtolower($credential->getAddress());
        $nonce = $this->rpc->getTransactionCount($from, 'pending');

        $data = $this->buildSponsoredTransferData($payload, (string) $request->signature);
        $raw = [
            'nonce' => $this->intToHex($nonce),
            'gasPrice' => $this->rpc->gasPrice(),
            'gasLimit' => $this->intToHex(250000),
            'to' => $this->executorAddress(),
            'value' => $this->intToHex(0),
            'data' => $data,
            'chainId' => (int) $request->chain_id,
        ];

        $signed = $credential->signTransaction($raw);
        $txHash = $this->rpc->sendRawTransaction($signed);

        $request->update([
            'tx_hash' => $txHash,
            'status' => PmCustodyTransferRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'raw_response_json' => [
                'tx_hash' => $txHash,
                'raw' => [
                    'to' => $raw['to'],
                    'data' => $raw['data'],
                    'chainId' => $raw['chainId'],
                ],
            ],
        ]);

        return [
            'request_id' => $request->id,
            'tx_hash' => $txHash,
            'status' => $request->status,
        ];
    }

    public function getTransferRequestStatus(PmCustodyTransferRequest $request): array
    {
        $receipt = $request->tx_hash ? $this->rpc->getTransactionReceipt($request->tx_hash) : null;
        if ($receipt && ($receipt['status'] ?? null) === '0x1' && $request->status === PmCustodyTransferRequest::STATUS_SUBMITTED) {
            $request->forceFill([
                'status' => PmCustodyTransferRequest::STATUS_CONFIRMED,
                'confirmed_at' => now(),
            ])->save();
        }

        return [
            'id' => $request->id,
            'status' => $request->status,
            'tx_hash' => $request->tx_hash,
            'receipt' => $receipt,
            'payload' => $request->raw_request_json,
        ];
    }

    public function verifyErc20TransferAuthorization(array $payload, string $signature, string $expectedSigner): bool
    {
        $wallet = PmCustodyWallet::where('signer_address', strtolower($expectedSigner))->first();
        if (!$wallet) {
            throw new \RuntimeException('签名钱包不存在');
        }

        $privateKey = $this->normalizePrivateKey($this->cipher->decryptString($wallet->private_key_ciphertext));
        $credential = Credential::fromKey(ltrim($privateKey, '0x'));

        return strtolower($credential->getAddress()) === strtolower($expectedSigner)
            && hash_equals($this->signPayloadHex($privateKey, $payload), strtolower($signature));
    }

    private function signPayload(PmCustodyWallet $wallet, array $payload): string
    {
        $privateKey = $this->normalizePrivateKey($this->cipher->decryptString($wallet->private_key_ciphertext));
        return $this->signPayloadHex($privateKey, $payload);
    }

    private function signPayloadHex(string $privateKey, array $payload): string
    {
        $hash = $this->payloadHash($payload);
        $ec = new \Elliptic\EC('secp256k1');
        $keyPair = $ec->keyFromPrivate(ltrim($privateKey, '0x'));
        $signature = $keyPair->sign($hash, ['canonical' => true]);
        $r = str_pad($signature->r->toString(16), 64, '0', STR_PAD_LEFT);
        $s = str_pad($signature->s->toString(16), 64, '0', STR_PAD_LEFT);
        $v = str_pad(dechex($signature->recoveryParam + 27), 2, '0', STR_PAD_LEFT);

        return '0x' . strtolower($r . $s . $v);
    }

    private function payloadHash(array $payload): string
    {
        ksort($payload);
        return '0x' . Keccak::hash(json_encode($payload, JSON_UNESCAPED_SLASHES), 256);
    }

    private function buildSponsoredTransferData(array $payload, string $signature): string
    {
        $selector = '0x8b9e4f93';

        $action = $this->padHex(bin2hex((string) ($payload['action'] ?? 'erc20_transfer')));
        $subWallet = $this->padHex(substr((string) $payload['subWallet'], 2));
        $token = $this->padHex(substr((string) $payload['token'], 2));
        $to = $this->padHex(substr((string) $payload['to'], 2));
        $amount = $this->padHex(gmp_strval(gmp_init((string) $payload['amount'], 10), 16));
        $nonce = $this->padHex(gmp_strval(gmp_init((string) $payload['nonce'], 10), 16));
        $deadline = $this->padHex(gmp_strval(gmp_init((string) $payload['deadline'], 10), 16));
        $sigOffset = $this->padHex('e0');
        $sig = strtolower(ltrim($signature, '0x'));
        $sigLength = $this->padHex(dechex((int) (strlen($sig) / 2)));
        $sigData = str_pad($sig, (int) ceil(strlen($sig) / 64) * 64, '0', STR_PAD_RIGHT);

        return $selector . $action . $subWallet . $token . $to . $amount . $nonce . $deadline . $sigOffset . $sigLength . $sigData;
    }

    private function walletData(PmCustodyWallet $wallet): array
    {
        return [
            'id' => $wallet->id,
            'wallet_role' => $wallet->wallet_role,
            'signer_address' => $wallet->signer_address,
            'funder_address' => $wallet->funder_address,
            'parent_wallet_id' => $wallet->parent_wallet_id,
            'purpose' => $wallet->purpose,
            'status' => $wallet->status,
        ];
    }

    private function assertAllowedToken(string $token): void
    {
        $allowed = config('pm.sponsored_transfer_allowed_tokens', []);
        if (is_array($allowed) && $allowed !== [] && !in_array(strtolower($token), $allowed, true)) {
            throw new \RuntimeException('当前 token 不在允许列表');
        }
    }

    private function assertAllowedAmount(string $amount): void
    {
        $maxAmount = trim((string) config('pm.sponsored_transfer_max_amount', '0'));
        if ($maxAmount !== '' && $maxAmount !== '0' && bccomp($amount, $maxAmount, 0) === 1) {
            throw new \RuntimeException('amount 超过允许上限');
        }
    }

    private function executorAddress(): string
    {
        $address = strtolower(trim((string) config('pm.sponsored_transfer_executor', '')));
        if (!EthSignature::isAddress($address)) {
            throw new \RuntimeException('PM_SPONSORED_TRANSFER_EXECUTOR 未配置或格式不正确');
        }

        return $address;
    }

    private function normalizePrivateKey(string $privateKey): string
    {
        $privateKey = strtolower(trim($privateKey));
        if (!str_starts_with($privateKey, '0x')) {
            $privateKey = '0x' . $privateKey;
        }

        return $privateKey;
    }

    private function padHex(string $value): string
    {
        return str_pad(Str::lower(ltrim($value, '0x')), 64, '0', STR_PAD_LEFT);
    }

    private function intToHex(int $value): string
    {
        return '0x' . dechex($value);
    }
}
