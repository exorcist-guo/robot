<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmSkipRoundOrder extends Model
{
    public const STATUS_PREDICTED = 'predicted';
    public const STATUS_MARKET_RESOLVED = 'market_resolved';
    public const STATUS_LIMIT_SUBMITTED = 'limit_submitted';
    public const STATUS_PARTIALLY_FILLED = 'partially_filled';
    public const STATUS_CANCEL_REQUESTED = 'cancel_requested';
    public const STATUS_MARKET_BUY_SUBMITTED = 'market_buy_submitted';
    public const STATUS_FILLED = 'filled';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_FAILED = 'failed';

    public const ACTIVE_STATUSES = [
        self::STATUS_PREDICTED,
        self::STATUS_MARKET_RESOLVED,
        self::STATUS_LIMIT_SUBMITTED,
        self::STATUS_PARTIALLY_FILLED,
        self::STATUS_CANCEL_REQUESTED,
        self::STATUS_MARKET_BUY_SUBMITTED,
    ];

    public const FINAL_STATUSES = [
        self::STATUS_FILLED,
        self::STATUS_SETTLED,
        self::STATUS_FAILED,
    ];

    protected $table = 'pm_skip_round_orders';

    protected $guarded = [];

    protected $casts = [
        'place_started_at' => 'datetime',
        'limit_placed_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'cancel_confirmed_at' => 'datetime',
        'market_buy_at' => 'datetime',
        'settled_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(PmSkipRoundStrategy::class, 'strategy_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PmSkipRoundStrategyLine::class, 'strategy_line_id');
    }

    public function isActive(): bool
    {
        return in_array((string) $this->status, self::ACTIVE_STATUSES, true);
    }

    public function isFinal(): bool
    {
        return in_array((string) $this->status, self::FINAL_STATUSES, true);
    }
}
