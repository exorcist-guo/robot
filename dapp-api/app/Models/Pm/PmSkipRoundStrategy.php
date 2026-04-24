<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PmSkipRoundStrategy extends Model
{
    protected $table = 'pm_skip_round_strategies';

    protected $guarded = [];

    protected $casts = [
        'config_snapshot' => 'array',
        'last_ran_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PmSkipRoundStrategyLine::class, 'strategy_id');
    }

    public function markets(): HasMany
    {
        return $this->hasMany(PmSkipRoundMarket::class, 'strategy_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PmSkipRoundOrder::class, 'strategy_id');
    }
}
