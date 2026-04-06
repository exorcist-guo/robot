<?php

namespace App\Models;

use App\Traits\BelongsToMember;
use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * IncomeRecord - 收益记录
 *
 * @property int $id
 * @property int $member_id
 * @property float $amount
 * @property string $type
 * @property string|null $tx_hash
 * @property int|null $contract_dynamic_id
 * @property int|null $performance_record_id
 * @property int|null $from_grab_id
 * @property string|null $from_address
 * @property int|null $block_number
 * @property \Illuminate\Support\Carbon|null $time_stamp
 * @property string|null $remark
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ContractDynamic|null $contractDynamic
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\PerformanceRecord|null $performanceRecord
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord filterMember($memberId)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord query()
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereBlockNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereContractDynamicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereFromAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereFromGrabId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereMemberId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord wherePerformanceRecordId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereRemark($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereTimeStamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereTxHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|IncomeRecord whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class IncomeRecord extends Model
{
    use HasDateTimeFormatter,BelongsToMember;

    protected $table = 'income_records';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:8',
        'time_stamp' => 'datetime',
    ];

    // 收益类型常量
    const TYPE_RANDOM_REWARD = 'random_reward';      // 随机奖励 (1-20 USDT)
    const TYPE_DIRECT_REWARD = 'direct_reward';      // 直推奖励 (1 USDT)
    const TYPE_TEAM_REWARD = 'team_reward';          // 团队奖励 (极差制)
    const TYPE_SUPER_PRIZE = 'super_prize';          // 超级大奖 (最后抢红包者, 64% of totalPrizePool)
    const TYPE_SECOND_PRIZE = 'second_prize';        // 二等奖 (倒数2-11人, 16% of totalPrizePool)
    const TYPE_LUCKY_REWARD = 'lucky_reward';        // 幸运奖 (千分之1几率, 10% of totalPrizePool)

    /**
     * 收益类型列表
     */
    const TYPE_MAP = [
        self::TYPE_RANDOM_REWARD => '随机奖励',
        self::TYPE_DIRECT_REWARD => '直推奖励',
        self::TYPE_TEAM_REWARD => '团队奖励',
        self::TYPE_SUPER_PRIZE => '超级大奖',
        self::TYPE_SECOND_PRIZE => '大奖池',
        self::TYPE_LUCKY_REWARD => '幸运奖',
    ];

    // 关联用户
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    // 关联合约动态
    public function contractDynamic()
    {
        return $this->belongsTo(ContractDynamic::class, 'contract_dynamic_id');
    }

    // 关联业绩记录
    public function performanceRecord()
    {
        return $this->belongsTo(PerformanceRecord::class, 'performance_record_id');
    }
}
