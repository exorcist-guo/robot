<?php

namespace App\Models\Pm;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 
 *
 * @property int $id
 * @property string $address
 * @property string|null $proxy_wallet
 * @property string|null $username
 * @property string|null $x_username
 * @property string|null $profile_image
 * @property bool $verified_badge
 * @property int $week_rank
 * @property int $month_rank
 * @property string|null $week_volume
 * @property string|null $month_volume
 * @property string|null $week_pnl
 * @property string|null $month_pnl
 * @property \Illuminate\Support\Carbon|null $last_ranked_at
 * @property array|null $raw
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pm\PmLeaderboardDailyStat> $dailyStats
 * @property-read int|null $daily_stats_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pm\PmLeaderboardUserTrade> $trades
 * @property-read int|null $trades_count
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereLastRankedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereMonthPnl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereMonthRank($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereMonthVolume($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereProfileImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereProxyWallet($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereRaw($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereVerifiedBadge($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereWeekPnl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereWeekRank($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereWeekVolume($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardUser whereXUsername($value)
 * @mixin \Eloquent
 */
class PmLeaderboardUser extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_leaderboard_users';

    protected $guarded = [];

    protected $casts = [
        'verified_badge' => 'boolean',
        'last_ranked_at' => 'datetime',
        'raw' => 'array',
    ];

    public function trades(): HasMany
    {
        return $this->hasMany(PmLeaderboardUserTrade::class, 'leaderboard_user_id');
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(PmLeaderboardDailyStat::class, 'leaderboard_user_id');
    }
}
