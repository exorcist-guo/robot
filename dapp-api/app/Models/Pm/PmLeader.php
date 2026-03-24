<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property string $input_address 录入地址(小写)
 * @property string $proxy_wallet proxyWallet(小写)
 * @property string|null $display_name
 * @property string|null $avatar_url
 * @property int $status 状态: 1=启用 0=禁用
 * @property \Illuminate\Support\Carbon|null $last_seen_trade_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pm\PmCopyTask> $copyTasks
 * @property-read int|null $copy_tasks_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pm\PmLeaderTrade> $trades
 * @property-read int|null $trades_count
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader whereAvatarUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader whereInputAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader whereLastSeenTradeAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader whereProxyWallet($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeader whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmLeader extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_leaders';

    protected $guarded = [];

    protected $casts = [
        'last_seen_trade_at' => 'datetime',
    ];

    public function trades(): HasMany
    {
        return $this->hasMany(PmLeaderTrade::class, 'leader_id');
    }

    public function copyTasks(): HasMany
    {
        return $this->hasMany(PmCopyTask::class, 'leader_id');
    }
}
