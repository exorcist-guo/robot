<?php

namespace App\Services\Pm;

use kornrunner\Keccak;

class EthSignature
{
    public static function normalizeAddress(string $address): string
    {
        $address = strtolower(trim($address));
        if (!str_starts_with($address, '0x')) {
            $address = '0x' . $address;
        }
        return $address;
    }

    public static function isAddress(string $address): bool
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', $address);
    }

    /**
     * 计算 personal_sign 的消息哈希（EIP-191）：
     * keccak256("\x19Ethereum Signed Message:\n" + len(message) + message)
     */
    public static function personalMessageHash(string $message): string
    {
        $prefix = "\x19Ethereum Signed Message:\n" . strlen($message);
        return Keccak::hash($prefix . $message, 256);
    }

    /**
     * 从签名恢复地址。
     *
     * @param string $message 明文 message（不是 hex）
     * @param string $signature 0x{r}{s}{v}，v 可为 0/1/27/28
     */
    public static function recoverAddress(string $message, string $signature): string
    {
        $signature = strtolower(trim($signature));
        if (str_starts_with($signature, '0x')) {
            $signature = substr($signature, 2);
        }

        if (strlen($signature) !== 130) {
            throw new \InvalidArgumentException('signature 长度必须为 65 bytes (130 hex)');
        }

        $r = substr($signature, 0, 64);
        $s = substr($signature, 64, 64);
        $vHex = substr($signature, 128, 2);
        $v = hexdec($vHex);
        if ($v === 0 || $v === 1) {
            $v += 27;
        }

        $hash = self::personalMessageHash($message);

        // 使用项目中已安装的 kornrunner/ethereum-util
        $publicKey = \kornrunner\Eth::ecRecover($hash, $r, $s, $v);

        // publicKey: 04 + x(64) + y(64)
        $publicKey = strtolower($publicKey);
        if (str_starts_with($publicKey, '0x')) {
            $publicKey = substr($publicKey, 2);
        }
        if (!str_starts_with($publicKey, '04') || strlen($publicKey) !== 130) {
            throw new \RuntimeException('恢复的公钥格式不正确');
        }

        $pubKeyNoPrefix = substr($publicKey, 2);
        $addr = '0x' . substr(Keccak::hash(hex2bin($pubKeyNoPrefix), 256), -40);
        return strtolower($addr);
    }
}
