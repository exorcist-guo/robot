<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property int $order_intent_id
 * @property string|null $poly_order_id CLOB order id
 * @property string|null $exchange_nonce
 * @property int $status 0=new 1=submitted 2=filled 3=partial 4=canceled 5=rejected 6=error
 * @property array $request_payload
 * @property array|null $response_payload
 * @property string|null $error_code
 * @property string|null $failure_category
 * @property int $is_retryable
 * @property int $retry_count
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $last_sync_at
 * @property int $filled_usdc
 * @property string|null $avg_price
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Pm\PmOrderIntent $intent
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereAvgPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereErrorCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereExchangeNonce($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereFailureCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereFilledUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereIsRetryable($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereLastSyncAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereOrderIntentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder wherePolyOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereRequestPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereResponsePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereRetryCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereSubmittedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmOrder extends Model
{
    use HasDateTimeFormatter;

    public const STATUS_NEW = 0;
    public const STATUS_SUBMITTED = 1;
    public const STATUS_FILLED = 2;
    public const STATUS_PARTIAL = 3;
    public const STATUS_CANCELED = 4;
    public const STATUS_REJECTED = 5;
    public const STATUS_ERROR = 6;

    protected $table = 'pm_orders';

    protected $guarded = [];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'submitted_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PmOrderIntent::class, 'order_intent_id');
    }
}
