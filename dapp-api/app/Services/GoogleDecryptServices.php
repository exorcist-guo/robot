<?php

namespace App\Services;

class GoogleDecryptServices
{
    public static function getPublicKey(): string
    {
        $path = (string) config('pm.google_public_key_path', config_path('public_key.pem'));
        $key = @file_get_contents($path);
        if (!is_string($key) || trim($key) === '') {
            throw new \RuntimeException('Google 公钥未配置');
        }

        return $key;
    }

    public static function getPrivateKey(): string
    {
        $path = (string) config('pm.google_private_key_path', config_path('private_key.pem'));
        $key = @file_get_contents($path);
        if (!is_string($key) || trim($key) === '') {
            throw new \RuntimeException('Google 私钥未配置');
        }

        return $key;
    }

    public static function encrypt(string $data): string
    {
        $encrypted = null;
        if (!openssl_public_encrypt($data, $encrypted, self::getPublicKey())) {
            throw new \RuntimeException('Google 公钥加密失败');
        }

        return base64_encode((string) $encrypted);
    }

    public static function decrypt(string $data): string
    {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new \RuntimeException('Google 私钥解密失败: 无效密文');
        }

        $decrypted = null;
        if (!openssl_private_decrypt($decoded, $decrypted, self::getPrivateKey())) {
            throw new \RuntimeException('Google 私钥解密失败');
        }

        return (string) $decrypted;
    }
}
