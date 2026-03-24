<?php

namespace App\Services\Pm;

class TailSweepSymbolRegistry
{
    public function __construct(private readonly TailSweepPriceCache $priceCache)
    {
    }

    /**
     * 从手动配置中读取需要订阅的 symbol 列表。
     *
     * @return array<int,string>
     */
    public function desiredSymbols(): array
    {
        $configured = config('pm.tail_sweep_price_symbols', []);
        if (!is_array($configured)) {
            $configured = [];
        }

        $normalized = [];
        foreach ($configured as $symbol) {
            $normalized[] = $this->priceCache->normalizeSymbol(is_string($symbol) ? $symbol : null);
        }

        $normalized = array_values(array_unique(array_filter($normalized, static fn (string $symbol) => $symbol !== '')));
        sort($normalized);

        return $normalized;
    }
}
