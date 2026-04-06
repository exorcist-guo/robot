<?php

namespace SWeb3;

use Brick\Math\BigInteger;

/**
 * 极简 ABI 编码工具（满足 SleepFinance\Encoder 的 EncodeGroup 需求）。
 *
 * 说明：
 * - 仅实现 EIP-712 所需的“静态类型 32-bytes word”编码
 * - 返回值为 **hex 字符串（不带 0x）**
 */
class ABI
{
    /**
     * @param array<int, string> $types
     * @param array<int, mixed> $values
     */
    public static function EncodeGroup(array $types, array $values): string
    {
        $out = '';

        foreach ($types as $i => $type) {
            $value = $values[$i] ?? null;
            $out .= self::encodeSingle((string) $type, $value);
        }

        return $out;
    }

    private static function encodeSingle(string $type, mixed $value): string
    {
        $type = trim($type);

        // bytes32
        if ($type === 'bytes32') {
            $hex = self::strip0x((string) $value);
            if (!ctype_xdigit($hex)) {
                throw new \InvalidArgumentException('bytes32 必须为 hex');
            }
            return str_pad(strtolower($hex), 64, '0', STR_PAD_LEFT);
        }

        // address
        if ($type === 'address') {
            $hex = self::strip0x((string) $value);
            if (!ctype_xdigit($hex) || strlen($hex) !== 40) {
                throw new \InvalidArgumentException('address 必须为 20 bytes hex');
            }
            return str_pad(strtolower($hex), 64, '0', STR_PAD_LEFT);
        }

        // bool
        if ($type === 'bool') {
            $v = $value ? 1 : 0;
            return str_pad(dechex($v), 64, '0', STR_PAD_LEFT);
        }

        // uintX / intX（EIP-712 里最终都是 32 bytes word）
        if (preg_match('/^(u?int)(\d{0,3})$/', $type) === 1) {
            // 兼容 value 既可能是 int，也可能是 "123..." 字符串
            if (is_int($value)) {
                $bi = BigInteger::of($value);
            } else {
                $s = trim((string) $value);
                if ($s === '') {
                    $s = '0';
                }

                // 允许 "0x.." 形式
                if (str_starts_with(strtolower($s), '0x')) {
                    $bi = BigInteger::fromBase(self::strip0x($s), 16);
                } else {
                    $bi = BigInteger::of($s);
                }
            }

            $hex = $bi->toBase(16);
            if ($hex === '-') {
                throw new \InvalidArgumentException('不支持负数');
            }

            return str_pad(strtolower($hex), 64, '0', STR_PAD_LEFT);
        }

        throw new \InvalidArgumentException('不支持的 ABI 类型: ' . $type);
    }

    private static function strip0x(string $hex): string
    {
        $hex = trim($hex);
        return str_starts_with(strtolower($hex), '0x') ? substr($hex, 2) : $hex;
    }
}
