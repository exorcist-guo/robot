<?php

namespace App\Models\Pm;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * 
 *
 * @property int $id
 * @property string $symbol
 * @property \Illuminate\Support\Carbon $snapshot_at
 * @property string $current_price
 * @property string|null $up_entry_price5m
 * @property string|null $down_entry_price5m
 * @property string|null $up_entry_price15m
 * @property string|null $down_entry_price15m
 * @property int $target_usdc 按1e6存储的固定金额
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereCurrentPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereDownEntryPrice15m($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereDownEntryPrice5m($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereSnapshotAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereSymbol($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereTargetUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereUpEntryPrice15m($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereUpEntryPrice5m($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepMarketSnapshot whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmTailSweepMarketSnapshot extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_tail_sweep_market_snapshots';

    protected $guarded = [];

    protected $casts = [
        'snapshot_at' => 'datetime',
    ];
}
