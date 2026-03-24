<?php

namespace App\Services\Pm;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use SleepFinance\Eip712;
use kornrunner\Secp256k1;

class PolymarketOrderSigner
{
    public function __construct(
        private readonly PolymarketOrderTypedDataBuilder $builder
    ) {}

    /**
     * @param array<string, mixed> $order
     */
    public function sign(array $order, string $privateKey): string
    {
        $typedData = $this->builder->build($order);
        $digest = (new Eip712($typedData))->hashTypedDataV4();

        $privateKey = strtolower(trim($privateKey));
        if (str_starts_with($privateKey, '0x')) {
            $privateKey = substr($privateKey, 2);
        }

        $secp = new Secp256k1();
        $sig = $secp->sign($digest, $privateKey);

        $r = str_pad(gmp_strval($sig->getR(), 16), 64, '0', STR_PAD_LEFT);
        $s = str_pad(gmp_strval($sig->getS(), 16), 64, '0', STR_PAD_LEFT);
        $v = 27 + $sig->getRecoveryParam();

        return '0x' . $r . $s . str_pad(dechex($v), 2, '0', STR_PAD_LEFT);
    }

    public function sideToInt(string $side): int
    {
        return strtoupper($side) === 'SELL' ? 1 : 0;
    }

    /**
     * Polymarket limit order:
     * - BUY: maker gives USDC, takes outcome token
     * - SELL: maker gives outcome token, takes USDC
     *
     * @return array{makerAmount:string,takerAmount:string}
     */
    public function makeAmounts(string $side, string $price, string $size): array
    {
        $priceDec = BigDecimal::of($price);
        $sizeDec = BigDecimal::of($size);

        // CLOB 使用 fixed-math 6 decimals
        $tokenAtomic = $sizeDec->multipliedBy('1000000')->toScale(0, RoundingMode::DOWN);
        $usdcAtomic = $sizeDec->multipliedBy($priceDec)->multipliedBy('1000000')->toScale(0, RoundingMode::DOWN);

        if (strtoupper($side) === 'SELL') {
            return [
                'makerAmount' => $tokenAtomic->__toString(),
                'takerAmount' => $usdcAtomic->__toString(),
            ];
        }

        return [
            'makerAmount' => $usdcAtomic->__toString(),
            'takerAmount' => $tokenAtomic->__toString(),
        ];
    }
}
