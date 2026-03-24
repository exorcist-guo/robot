<?php

namespace App\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * PerformanceRecord - 业绩记录
 *
 * @property int $id
 * @property int $member_id
 * @property int $parent_id
 * @property float $amount
 * @property int|null $contract_dynamic_id
 * @property \Illuminate\Support\Carbon|null $time_stamp
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\ContractDynamic|null $contractDynamic
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\IncomeRecord> $incomeRecords
 * @property-read int|null $income_records_count
 * @property-read \App\Models\Member|null $member
 * @property-read \App\Models\Member|null $parent
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord query()
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord whereContractDynamicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord whereMemberId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord whereParentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord whereTimeStamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PerformanceRecord whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PerformanceRecord extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'performance_records';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:8',
        'time_stamp' => 'datetime',
    ];

    // 关联用户
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    // 关联上级用户
    public function parent()
    {
        return $this->belongsTo(Member::class, 'parent_id');
    }

    // 关联合约动态
    public function contractDynamic()
    {
        return $this->belongsTo(ContractDynamic::class, 'contract_dynamic_id');
    }

    // 收益记录 (一对多)
    public function incomeRecords()
    {
        return $this->hasMany(IncomeRecord::class, 'performance_record_id');
    }


}
