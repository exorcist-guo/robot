<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id 订单主键 ID
 * @property int $order_intent_id 关联的下单意图 ID
 * @property string|null $leader_role maker|taker
 * @property string|null $token_id outcome token id
 * @property string|null $poly_order_id Polymarket CLOB 订单 ID
 * @property string|null $exchange_nonce 交易所侧 nonce / 去重标识
 * @property int $status 本地下单状态：0=new 1=submitted 2=filled 3=partial 4=canceled 5=rejected 6=error
 * @property array $request_payload 下单请求原始快照
 * @property array|null $response_payload 下单返回或远端订单原始快照
 * @property string|null $error_code 下单失败错误码
 * @property string|null $failure_category 下单失败分类
 * @property int $is_retryable 是否可重试
 * @property int $retry_count 已重试次数
 * @property string|null $error_message 下单失败错误信息
 * @property \Illuminate\Support\Carbon|null $submitted_at 提交下单时间
 * @property \Illuminate\Support\Carbon|null $last_sync_at 最近一次同步订单时间
 * @property int $filled_usdc 已成交金额，单位为 USDC 6 位精度整数
 * @property string|null $avg_price 平均成交价格
 * @property string|null $original_size 原始下单份额数量
 * @property string|null $filled_size 已成交份额数量
 * @property string|null $order_price 下单价格
 * @property string|null $outcome 下单方向 / outcome
 * @property string|null $order_type 订单类型
 * @property string|null $remote_order_status 远端原始订单状态
 * @property bool $is_settled 订单是否已结算
 * @property \Illuminate\Support\Carbon|null $settled_at 订单结算确认时间
 * @property string|null $winning_outcome 市场最终胜出 outcome
 * @property string|null $settlement_source 结算结果来源
 * @property int|null $position_notional_usdc 持仓本金，单位为 USDC 6 位精度整数
 * @property int|null $pnl_usdc 订单最终盈亏，单位为 USDC 6 位精度整数
 * @property int|null $profit_usdc 订单收益金额，单位为 USDC 6 位精度整数
 * @property int|null $roi_bps 收益率，单位为基点 bps
 * @property bool|null $is_win 订单结算后是否为赢单
 * @property \Illuminate\Support\Carbon|null $last_profit_sync_at 最近一次同步收益时间
 * @property int $claim_status 兑奖状态：0=not_needed 1=pending 2=claiming 3=claimed 4=failed 5=skipped
 * @property int|null $claimable_usdc 当前可兑奖金额，单位为 USDC 6 位精度整数
 * @property string|null $claim_tx_hash 兑奖链上交易哈希
 * @property int $claim_attempts 已尝试兑奖次数
 * @property string|null $claim_last_error 最近一次兑奖失败原因
 * @property \Illuminate\Support\Carbon|null $claim_requested_at 发起兑奖时间
 * @property \Illuminate\Support\Carbon|null $claim_completed_at 兑奖完成时间
 * @property \Illuminate\Support\Carbon|null $claim_last_checked_at 最近一次检查兑奖状态时间
 * @property array|null $settlement_payload 订单结算过程的原始快照
 * @property array|null $claim_payload 兑奖请求、回执与链上结果快照
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
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereClaimAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereClaimCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereClaimLastCheckedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereClaimLastError($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereClaimPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereClaimRequestedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereClaimStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereClaimTxHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereClaimableUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereFilledSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereIsSettled($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereIsWin($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereLastProfitSyncAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereOrderPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereOrderType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereOriginalSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereOutcome($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder wherePnlUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder wherePositionNotionalUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereProfitUsdc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereRemoteOrderStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereRoiBps($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereSettledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereSettlementPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereSettlementSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmOrder whereWinningOutcome($value)
 * @mixin \Eloquent
 */
class PmOrder extends Model
{
    use HasDateTimeFormatter;

    /** 订单新建，尚未提交到 Polymarket */
    public const STATUS_NEW = 0;
    /** 已提交到 Polymarket，等待成交或同步 */
    public const STATUS_SUBMITTED = 1;
    /** 订单已全部成交 */
    public const STATUS_FILLED = 2;
    /** 订单部分成交 */
    public const STATUS_PARTIAL = 3;
    /** 订单已取消 */
    public const STATUS_CANCELED = 4;
    /** 订单被拒绝 */
    public const STATUS_REJECTED = 5;
    /** 下单或同步过程中发生错误 */
    public const STATUS_ERROR = 6;

    /** 无需兑奖：未盈利、未结算或无可兑奖金额 */
    public const CLAIM_STATUS_NOT_NEEDED = 0;
    /** 待兑奖：已满足兑奖条件，等待发起链上交易 */
    public const CLAIM_STATUS_PENDING = 1;
    /** 兑奖中：已发起或正在确认链上交易 */
    public const CLAIM_STATUS_CLAIMING = 2;
    /** 已兑奖：链上交易已提交 */
    public const CLAIM_STATUS_CLAIMED = 3;
    /** 兑奖失败：链上交易失败或兑奖流程异常 */
    public const CLAIM_STATUS_FAILED = 4;
    /** 已跳过：因规则或条件限制未执行兑奖 */
    public const CLAIM_STATUS_SKIPPED = 5;
    /** 已确认到账：链上交易已确认并成功执行 */
    public const CLAIM_STATUS_CONFIRMED = 6;

    protected $table = 'pm_orders';

    protected $guarded = [];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'submitted_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'is_settled' => 'boolean',
        'settled_at' => 'datetime',
        'is_win' => 'boolean',
        'last_profit_sync_at' => 'datetime',
        'claim_requested_at' => 'datetime',
        'claim_completed_at' => 'datetime',
        'claim_last_checked_at' => 'datetime',
        'settlement_payload' => 'array',
        'claim_payload' => 'array',
    ];

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PmOrderIntent::class, 'order_intent_id');
    }
}
