<?php

namespace App\Models\Pm;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

class PmTailSweepMarketSnapshot extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_tail_sweep_market_snapshots';

    protected $guarded = [];

    protected $casts = [
        'snapshot_at' => 'datetime',
        'round_start_at' => 'datetime',
        'round_end_at' => 'datetime',
        'raw' => 'array',
    ];
}
