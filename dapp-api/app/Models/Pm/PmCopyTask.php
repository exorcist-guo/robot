<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property int $member_id
 * @property int|null $leader_id
 * @property int $status 状态: 1=启用 0=暂停
 * @property string $mode leader_copy|tail_sweep
 * @property string|null $market_slug
 * @property string|null $market_id
 * @property string|null $market_question
 * @property string|null $market_symbol
 * @property string|null $resolution_source
 * @property string|null $token_yes_id
 * @property string|null $token_no_id
 * @property string|null $price_to_beat
 * @property \Illuminate\Support\Carbon|null $market_end_at
 * @property int $ratio_bps 跟单比例(bps), 10000=100%
 * @property int $min_usdc 最小单笔USDC(1e6)
 * @property int $max_usdc 最大单笔USDC(1e6)
 * @property int $max_slippage_bps 最大滑点(bps)
 * @property bool $allow_partial_fill
 * @property int|null $daily_max_usdc 每日最大USDC(1e6)
 * @property int $tail_order_usdc 扫尾盘固定下单金额(1e6)
 * @property string|null $tail_trigger_amount 扫尾盘触发阈值
 * @property int $tail_time_limit_seconds 最后多少秒允许触发
 * @property int $tail_loss_stop_count 累计亏损多少单自动停
 * @property int $tail_loss_count 当前累计亏损单数
 * @property string|null $tail_round_started_value 本轮开始值
 * @property string|null $tail_last_triggered_round_key 最后一次触发轮次
 * @property \Illuminate\Support\Carbon|null $tail_loss_stopped_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Pm\PmLeader $leader
 * @property-read \App\Models\Pm\PmMember $member
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pm\PmOrderIntent> $orderIntents
 * @property-read int|null $order_intents_count
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereAllowPartialFill($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereDailyMaxUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereLeaderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereMaxSlippageBps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereMaxUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereMemberId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereMinUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereRatioBps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask withTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask withoutTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereMarketEndAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereMarketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereMarketQuestion($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereMarketSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereMarketSymbol($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask wherePriceToBeat($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereResolutionSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereTailLastTriggeredRoundKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereTailLossCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereTailLossStopCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereTailLossStoppedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereTailOrderUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereTailRoundStartedValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereTailTimeLimitSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereTailTriggerAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereTokenNoId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCopyTask whereTokenYesId($value)
 * @mixin \Eloquent
 */
class PmCopyTask extends Model
{
    use SoftDeletes, HasDateTimeFormatter;

    public const MODE_LEADER_COPY = 'leader_copy';
    public const MODE_TAIL_SWEEP = 'tail_sweep';
    public const MODE_TAIL_SWEEP_MANY = 'tail_sweep_many';

    protected $table = 'pm_copy_tasks';

    protected $guarded = [];

    protected $casts = [
        'allow_partial_fill' => 'boolean',
        'market_end_at' => 'datetime',
        'tail_loss_stopped_at' => 'datetime',
        'tail_price_time_config' => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(PmMember::class, 'member_id');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(PmLeader::class, 'leader_id')->withDefault();
    }

    public function orderIntents(): HasMany
    {
        return $this->hasMany(PmOrderIntent::class, 'copy_task_id');
    }
}
