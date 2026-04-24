<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PmSkipRoundStrategyLine extends Model
{
    protected $table = 'pm_skip_round_strategy_lines';

    protected $guarded = [];

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(PmSkipRoundStrategy::class, 'strategy_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PmSkipRoundOrder::class, 'strategy_line_id');
    }
}
