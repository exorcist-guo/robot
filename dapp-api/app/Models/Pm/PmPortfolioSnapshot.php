<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property int $member_id
 * @property int|null $available_usdc
 * @property int|null $equity_usdc
 * @property int|null $pnl_today_usdc
 * @property int|null $pnl_total_usdc
 * @property \Illuminate\Support\Carbon $as_of
 * @property array|null $raw
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Pm\PmMember $member
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot whereAsOf($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot whereAvailableUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot whereEquityUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot whereMemberId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot wherePnlTodayUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot wherePnlTotalUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot whereRaw($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPortfolioSnapshot whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmPortfolioSnapshot extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_portfolio_snapshots';

    protected $guarded = [];

    protected $casts = [
        'as_of' => 'datetime',
        'raw' => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(PmMember::class, 'member_id');
    }
}
