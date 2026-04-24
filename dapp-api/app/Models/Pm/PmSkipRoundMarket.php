<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmSkipRoundMarket extends Model
{
    protected $table = 'pm_skip_round_markets';

    protected $guarded = [];

    protected $casts = [
        'round_start_at' => 'datetime',
        'round_end_at' => 'datetime',
        'resolved_at' => 'datetime',
        'market_payload' => 'array',
    ];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(PmSkipRoundStrategy::class, 'strategy_id');
    }
}
