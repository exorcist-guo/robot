<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property int $member_id
 * @property int $sub_wallet_id
 * @property int $master_wallet_id
 * @property int $chain_id
 * @property string $token_address
 * @property string $from_address
 * @property string $to_address
 * @property string $amount
 * @property string $nonce
 * @property int $deadline_at
 * @property string $action
 * @property string|null $signature_payload_hash
 * @property string|null $signature
 * @property string|null $tx_hash
 * @property int $status 0=draft 1=signed 2=submitted 3=confirmed 4=failed 5=expired
 * @property string|null $failure_reason
 * @property array|null $raw_request_json
 * @property array|null $raw_response_json
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Pm\PmCustodyWallet $masterWallet
 * @property-read \App\Models\Pm\PmMember $member
 * @property-read \App\Models\Pm\PmCustodyWallet $subWallet
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereChainId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereConfirmedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereDeadlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereFailureReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereFromAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereMasterWalletId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereMemberId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereNonce($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereRawRequestJson($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereRawResponseJson($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereSignature($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereSignaturePayloadHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereSubWalletId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereSubmittedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereToAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereTokenAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereTxHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmCustodyTransferRequest whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmCustodyTransferRequest extends Model
{
    use HasDateTimeFormatter;

    public const STATUS_DRAFT = 0;
    public const STATUS_SIGNED = 1;
    public const STATUS_SUBMITTED = 2;
    public const STATUS_CONFIRMED = 3;
    public const STATUS_FAILED = 4;
    public const STATUS_EXPIRED = 5;

    protected $table = 'pm_custody_transfer_requests';

    protected $guarded = [];

    protected $casts = [
        'raw_request_json' => 'array',
        'raw_response_json' => 'array',
        'submitted_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(PmMember::class, 'member_id');
    }

    public function subWallet(): BelongsTo
    {
        return $this->belongsTo(PmCustodyWallet::class, 'sub_wallet_id');
    }

    public function masterWallet(): BelongsTo
    {
        return $this->belongsTo(PmCustodyWallet::class, 'master_wallet_id');
    }

    public function isExpired(): bool
    {
        return (int) $this->deadline_at < time();
    }
}
