<?php

namespace App\Services\Pm;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Dcat\Admin\Traits\HasDateTimeFormatter;

class CustodyCipher
{
    use HasDateTimeFormatter;
    private Encrypter $encrypter;

    public function __construct(?string $key = null)
    {
        $key ??= config('pm.custody_key');

        if (!is_string($key) || $key === '') {
            throw new \RuntimeException('PM_CUSTODY_KEY 未配置');
        }

        // 支持两种格式：
        // 1) base64:xxxx（Laravel 常用格式）
        // 2) 纯 base64 字符串
        if (Str::startsWith($key, 'base64:')) {
            $raw = base64_decode(substr($key, 7), true);
        } else {
            $raw = base64_decode($key, true);
        }

        if ($raw === false || strlen($raw) !== 32) {
            throw new \RuntimeException('PM_CUSTODY_KEY 必须是 32 bytes 的 base64 编码');
        }

        $this->encrypter = new Encrypter($raw, 'AES-256-CBC');
    }

    public function encryptString(string $plaintext): string
    {
        return $this->encrypter->encryptString($plaintext);
    }

    public function decryptString(string $ciphertext): string
    {
        return $this->encrypter->decryptString($ciphertext);
    }
}
