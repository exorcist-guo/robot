<?php

namespace App\Models\Pm;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
