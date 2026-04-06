<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property string $address 钱包地址(小写)
 * @property string $nonce 一次性 nonce
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property string|null $ip
 * @property string|null $ua_hash
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce whereIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce whereNonce($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce whereUaHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmAuthNonce whereUsedAt($value)
 * @mixin \Eloquent
 */
class PmAuthNonce extends Model
{
    use HasDateTimeFormatter;
    protected $table = 'pm_auth_nonces';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
