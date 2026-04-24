<?php

namespace App\Services\Pm;

class SkipRoundOrderbookService
{
    /**
     * @param array<string,mixed> $book
     * @return array<string,mixed>|null
     */
    public function pickHighestAskLevelForBuy(array $book): ?array
    {
        // BUY 挂限价单时，这里不再看卖盘 asks，
        // 而是看当前买盘 bids，并取价格最高的一档，确保挂单站在“最优买价”位置。
        $bids = $book['bids'] ?? [];
        if (!is_array($bids) || $bids === []) {
            return null;
        }

        // 按价格从高到低排序，第一档就是当前盘口里的最高买价。
        usort($bids, static function (array $a, array $b): int {
            return ((float) ($b['price'] ?? 0)) <=> ((float) ($a['price'] ?? 0));
        });

        // 取排序后的第一档，作为本次 BUY 限价挂单的目标层级。
        $best = $bids[0] ?? null;
        if (!is_array($best)) {
            return null;
        }

        // 提取价格和数量；缺任一关键字段都视为无效盘口层级。
        $price = trim((string) ($best['price'] ?? ''));
        $size = trim((string) ($best['size'] ?? ''));
        if ($price === '' || $size === '') {
            return null;
        }

        // 返回统一结构：
        // - price：后续真实挂 BUY 限价单用的价格（最高买价）
        // - size：该档位已有买盘数量
        // - raw：保留原始盘口，方便写 snapshot 做复盘
        return [
            'price' => $price,
            'size' => $size,
            'raw' => $best,
        ];
    }
}
