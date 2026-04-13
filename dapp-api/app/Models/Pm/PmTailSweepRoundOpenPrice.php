<?php

namespace App\Models\Pm;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

class PmTailSweepRoundOpenPrice extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_tail_sweep_round_open_prices';

    protected $guarded = [];

    protected $casts = [
        'round_start_at' => 'datetime',
        'round_end_at' => 'datetime',
    ];
}
