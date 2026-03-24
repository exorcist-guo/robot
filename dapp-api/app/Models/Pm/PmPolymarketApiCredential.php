<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property int $custody_wallet_id
 * @property string $api_key_ciphertext
 * @property string $api_secret_ciphertext
 * @property string $passphrase_ciphertext
 * @property int $encryption_version
 * @property \Illuminate\Support\Carbon|null $derived_at
 * @property \Illuminate\Support\Carbon|null $last_validated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Pm\PmCustodyWallet $custodyWallet
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential whereApiKeyCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential whereApiSecretCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential whereCustodyWalletId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential whereDerivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential whereEncryptionVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential whereLastValidatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential wherePassphraseCiphertext($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmPolymarketApiCredential whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmPolymarketApiCredential extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'pm_polymarket_api_credentials';

    protected $guarded = [];

    protected $casts = [
        'derived_at' => 'datetime',
        'last_validated_at' => 'datetime',
    ];

    public function custodyWallet(): BelongsTo
    {
        return $this->belongsTo(PmCustodyWallet::class, 'custody_wallet_id');
    }
}
