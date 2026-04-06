<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyWallet;
use App\Services\GoogleDecryptServices;
use EthTool\Credential;

class PmPrivateKeyResolver
{
    public function resolve(PmCustodyWallet $wallet): string
    {
        $encrypted = trim((string) $wallet->en_private_key);
        if ($encrypted === '') {
            throw new \RuntimeException('钱包未配置 en_private_key');
        }

        $privateKey = strtolower(trim(GoogleDecryptServices::decrypt($encrypted)));
        if (!str_starts_with($privateKey, '0x')) {
            $privateKey = '0x' . $privateKey;
        }

        if (!preg_match('/^0x[a-f0-9]{64}$/', $privateKey)) {
            throw new \RuntimeException('解密后的私钥格式不正确');
        }

        $credential = Credential::fromKey(ltrim($privateKey, '0x'));
        $resolvedAddress = strtolower($credential->getAddress());
        if ($resolvedAddress !== strtolower((string) $wallet->signer_address)) {
            throw new \RuntimeException('钱包 signer_address 与解密私钥不匹配');
        }

        return $privateKey;
    }

    public function credential(PmCustodyWallet $wallet): Credential
    {
        return Credential::fromKey(ltrim($this->resolve($wallet), '0x'));
    }
}
