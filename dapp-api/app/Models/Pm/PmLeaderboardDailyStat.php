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
 * @property \Illuminate\Support\Carbon $stat_date
 * @property int $day_total_orders
 * @property int $day_win_orders
 * @property int $day_loss_orders
 * @property int $day_win_rate_bps
 * @property int $day_invested_amount_usdc
 * @property int $day_profit_amount_usdc
 * @property int $week_total_orders
 * @property int $week_win_orders
 * @property int $week_loss_orders
 * @property int $week_win_rate_bps
 * @property int $week_invested_amount_usdc
 * @property int $week_profit_amount_usdc
 * @property int $month_total_orders
 * @property int $month_win_orders
 * @property int $month_loss_orders
 * @property int $month_win_rate_bps
 * @property int $month_invested_amount_usdc
 * @property int $month_profit_amount_usdc
 * @property int $all_total_orders
 * @property int $all_win_orders
 * @property int $all_loss_orders
 * @property int $all_win_rate_bps
 * @property int $all_invested_amount_usdc
 * @property int $all_profit_amount_usdc
 * @property array|null $raw
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Pm\PmLeaderboardUser $leaderboardUser
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereAllInvestedAmountUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereAllLossOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereAllProfitAmountUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereAllTotalOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereAllWinOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereAllWinRateBps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereDayInvestedAmountUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereDayLossOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereDayProfitAmountUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereDayTotalOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereDayWinOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereDayWinRateBps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereLeaderboardUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereMonthInvestedAmountUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereMonthLossOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereMonthProfitAmountUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereMonthTotalOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereMonthWinOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereMonthWinRateBps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereRaw($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereStatDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereWeekInvestedAmountUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereWeekLossOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereWeekProfitAmountUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereWeekTotalOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereWeekWinOrders($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderboardDailyStat whereWeekWinRateBps($value)
 * @mixin \Eloquent
 */
class PmLeaderboardDailyStat extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_leaderboard_daily_stats';

    protected $guarded = [];

    protected $casts = [
        'stat_date' => 'date',
        'raw' => 'array',
    ];

    public function leaderboardUser(): BelongsTo
    {
        return $this->belongsTo(PmLeaderboardUser::class, 'leaderboard_user_id');
    }
}
