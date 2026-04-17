<?php

namespace App\Models\Pm;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * 
 *
 * @property int $id
 * @property string $symbol
 * @property \Illuminate\Support\Carbon $round_start_at
 * @property \Illuminate\Support\Carbon|null $round_end_at
 * @property string|null $round_open_price
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepRoundOpenPrice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepRoundOpenPrice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepRoundOpenPrice query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepRoundOpenPrice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepRoundOpenPrice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepRoundOpenPrice whereRoundEndAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepRoundOpenPrice whereRoundOpenPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepRoundOpenPrice whereRoundStartAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepRoundOpenPrice whereSymbol($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmTailSweepRoundOpenPrice whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmTailSweepRoundOpenPrice extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_tail_sweep_round_open_prices';

    protected $guarded = [];

    protected $casts = [
        'round_start_at' => 'datetime',
        'round_end_at' => 'datetime',
    ];
}
