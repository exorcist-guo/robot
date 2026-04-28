<?php

namespace App\Models\Pm;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 *
 *
 * @property int $id
 * @property int $leaderboard_user_id
 * @property string $address
 * @property string $external_position_id
 * @property string|null $market_id
 * @property string|null $token_id
 * @property string|null $title
 * @property string|null $slug
 * @property string|null $outcome
 * @property string|null $opposite_outcome
 * @property string|null $avg_price
 * @property string|null $price
 * @property string|null $size
 * @property int $invested_amount_usdc
 * @property int $pnl_amount_usdc
 * @property int $profit_amount_usdc
 * @property int $loss_amount_usdc
 * @property bool|null $is_win
 * @property string|null $pnl_status
 * @property int|null $pnl_ratio_bps
 * @property string|null $order_status
 * @property bool $is_settled
 * @property \Illuminate\Support\Carbon|null $traded_at
 * @property \Illuminate\Support\Carbon|null $settled_at
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property array|null $raw
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Pm\PmLeaderboardUser $leaderboardUser
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUserTrade newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUserTrade newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUserTrade query()
 * @mixin \Eloquent
 */
class PmLeaderboardUserTrade extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_leaderboard_user_trades';

    protected $guarded = [];

    protected $casts = [
        'is_win' => 'boolean',
        'is_settled' => 'boolean',
        'traded_at' => 'datetime',
        'settled_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'raw' => 'array',
    ];

    public function leaderboardUser(): BelongsTo
    {
        return $this->belongsTo(PmLeaderboardUser::class, 'leaderboard_user_id');
    }
}
