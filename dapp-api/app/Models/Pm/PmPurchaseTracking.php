<?php

namespace App\Models\Pm;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 
 *
 * @property int $id
 * @property int $member_id
 * @property int|null $copy_task_id
 * @property int|null $leader_trade_id
 * @property int|null $order_intent_id
 * @property int|null $order_id
 * @property string|null $market_id
 * @property string $token_id
 * @property string $bought_size
 * @property string $remaining_size
 * @property string|null $avg_price
 * @property string $source_type
 * @property \Illuminate\Support\Carbon|null $opened_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property array|null $meta
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Pm\PmCopyTask|null $copyTask
 * @property-read \App\Models\Pm\PmLeaderTrade|null $leaderTrade
 * @property-read \App\Models\Pm\PmMember $member
 * @property-read \App\Models\Pm\PmOrder|null $order
 * @property-read \App\Models\Pm\PmOrderIntent|null $orderIntent
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereAvgPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereBoughtSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereClosedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereCopyTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereLeaderTradeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereMarketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereMemberId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereMeta($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereOpenedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereOrderIntentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereRemainingSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereSourceType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereTokenId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPurchaseTracking whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmPurchaseTracking extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_purchase_trackings';

    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(PmMember::class, 'member_id');
    }

    public function copyTask(): BelongsTo
    {
        return $this->belongsTo(PmCopyTask::class, 'copy_task_id');
    }

    public function leaderTrade(): BelongsTo
    {
        return $this->belongsTo(PmLeaderTrade::class, 'leader_trade_id');
    }

    public function orderIntent(): BelongsTo
    {
        return $this->belongsTo(PmOrderIntent::class, 'order_intent_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PmOrder::class, 'order_id');
    }
}
