<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property int $leader_id
 * @property string $trade_id 外部trade id
 * @property string|null $market_id
 * @property string|null $token_id Outcome token id
 * @property string $side BUY|SELL
 * @property string|null $price 成交价(字符串)
 * @property int|null $size_usdc 成交金额USDC(1e6)
 * @property array|null $raw
 * @property int $traded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Pm\PmLeader $leader
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereLeaderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereMarketId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereRaw($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereSide($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereSizeUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereTokenId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereTradeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereTradedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmLeaderTrade whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmLeaderTrade extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_leader_trades';

    protected $guarded = [];

    protected $casts = [
        'raw' => 'array',
    ];

    public function leader(): BelongsTo
    {
        return $this->belongsTo(PmLeader::class, 'leader_id');
    }
}
