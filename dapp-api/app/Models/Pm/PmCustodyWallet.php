<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property int $member_id
 * @property string $wallet_role 钱包角色: master/sub
 * @property int|null $parent_wallet_id 父钱包ID
 * @property string|null $purpose 用途
 * @property string|null $address 登录地址(小写)
 * @property string $signer_address 签名地址(EOA, 小写)
 * @property string|null $funder_address 资金地址/ProxyWallet(小写)
 * @property string|null $en_private_key Google 加密后的私钥
 * @property int $encryption_version
 * @property int $signature_type 签名类型: 0=EOA 1=ProxyEmail 2=ProxyWallet/Safe
 * @property string $exchange_nonce Exchange nonce (默认0)
 * @property int $status 状态: 1=启用 0=锁定
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Pm\PmPolymarketApiCredential|null $apiCredentials
 * @property-read \App\Models\Pm\PmMember $member
 * @property-read PmCustodyWallet|null $parentWallet
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PmCustodyWallet> $subWallets
 * @property-read int|null $sub_wallets_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pm\PmCustodyTransferRequest> $transferRequests
 * @property-read int|null $transfer_requests_count
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereEncryptionVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereExchangeNonce($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereFunderAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereMemberId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereParentWalletId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet wherePurpose($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereSignatureType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereSignerAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereWalletRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyWallet whereEnPrivateKey($value)
 * @mixin \Eloquent
 */
class PmCustodyWallet extends Model
{
    use HasDateTimeFormatter;

    public const ROLE_MASTER = 'master';
    public const ROLE_SUB = 'sub';

    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    protected $table = 'pm_custody_wallets';

    protected $guarded = [];

    public function member(): BelongsTo
    {
        return $this->belongsTo(PmMember::class, 'member_id');
    }

    public function apiCredentials(): HasOne
    {
        return $this->hasOne(PmPolymarketApiCredential::class, 'custody_wallet_id');
    }

    public function parentWallet(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_wallet_id');
    }

    public function subWallets(): HasMany
    {
        return $this->hasMany(self::class, 'parent_wallet_id');
    }

    public function transferRequests(): HasMany
    {
        return $this->hasMany(PmCustodyTransferRequest::class, 'sub_wallet_id');
    }

    public function isMaster(): bool
    {
        return $this->wallet_role === self::ROLE_MASTER;
    }

    public function isSub(): bool
    {
        return $this->wallet_role === self::ROLE_SUB;
    }

    public function tradingAddress(): string
    {
        return strtolower((string) ($this->funder_address ?: $this->signer_address));
    }
}
