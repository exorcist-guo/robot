<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property int $copy_task_id
 * @property int|null $leader_trade_id
 * @property int $member_id
 * @property string $token_id
 * @property string $side BUY|SELL
 * @property string|null $leader_price
 * @property int $target_usdc 目标USDC(1e6)
 * @property int $clamped_usdc 限制后USDC(1e6)
 * @property int $status 0=pending 1=submitted 2=skipped 3=failed
 * @property string|null $skip_reason
 * @property int $attempt_count
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property array|null $risk_snapshot
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Pm\PmCopyTask $copyTask
 * @property-read \App\Models\Pm\PmLeaderTrade $leaderTrade
 * @property-read \App\Models\Pm\PmMember $member
 * @property-read \App\Models\Pm\PmOrder|null $order
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereAttemptCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereClampedUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereCopyTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereLastErrorCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereLastErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereLeaderPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereLeaderTradeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereMemberId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereRiskSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereSide($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereSkipReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereTargetUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereTokenId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrderIntent whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmOrderIntent extends Model
{
    use HasDateTimeFormatter;

    public const STATUS_PENDING = 0;
    public const STATUS_SUBMITTED = 1;
    public const STATUS_SKIPPED = 2;
    public const STATUS_FAILED = 3;

    protected $table = 'pm_order_intents';

    protected $guarded = [];

    protected $casts = [
        'risk_snapshot' => 'array',
        'decision_payload' => 'array',
        'processing_started_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function copyTask(): BelongsTo
    {
        return $this->belongsTo(PmCopyTask::class, 'copy_task_id');
    }

    public function leaderTrade(): BelongsTo
    {
        return $this->belongsTo(PmLeaderTrade::class, 'leader_trade_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(PmMember::class, 'member_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(PmOrder::class, 'order_intent_id');
    }
}
