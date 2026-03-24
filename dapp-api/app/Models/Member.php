<?php

namespace App\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * Member
 *
 * @property int $id
 * @property int $pid
 * @property int $deep
 * @property string $path
 * @property string $address
 * @property int $level
 * @property string $performance
 * @property string $total_earnings
 * @property string $total_consumption
 * @property int $total_grab_count
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $set_level 是否手动设置等级 0-否 1-是
 * @property int $check_num 检查次数
 * @property string $level_hash 交易哈希
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Member> $children
 * @property-read int|null $children_count
 * @property-read mixed $parent_path_ids
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\IncomeRecord> $incomeRecords
 * @property-read int|null $income_records_count
 * @property-read Member|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PerformanceRecord> $performanceRecords
 * @property-read int|null $performance_records_count
 * @method static \Illuminate\Database\Eloquent\Builder|Member newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Member newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Member query()
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereCheckNum($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereDeep($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereLevelHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member wherePerformance($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member wherePid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereSetLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereTotalConsumption($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereTotalEarnings($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereTotalGrabCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Member whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Member extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'members';

    protected $guarded = [];

    protected $casts = [
        'performance' => 'decimal:8',
        'total_earnings' => 'decimal:8',
        'total_consumption' => 'decimal:8',
    ];

    // 关联的业绩记录
    public function performanceRecords()
    {
        return $this->hasMany(PerformanceRecord::class, 'member_id');
    }

    // 关联的收益记录
    public function incomeRecords()
    {
        return $this->hasMany(IncomeRecord::class, 'member_id');
    }

    // 获取所有上级
    public function getParentPathIdsAttribute()
    {
        $paths = explode('/', trim($this->path, '/'));
        return array_filter($paths, 'is_numeric');
    }

    // 获取上级用户
    public function parent()
    {
        return $this->belongsTo(Member::class, 'pid');
    }

    // 获取下级用户
    public function children()
    {
        return $this->hasMany(Member::class, 'pid');
    }

     const LEVEL = [
         1 => ['level'=>1 , 'performance'=>5000,'ratio' => 1 ],
         2 => ['level'=>2 , 'performance'=>10000,'ratio' => 2 ],
         3 => ['level'=>3 , 'performance'=>30000,'ratio' => 3 ],
         4 => ['level'=>4 , 'performance'=>50000,'ratio' => 4 ],
         5 => ['level'=>5 , 'performance'=>100000,'ratio' => 5 ],
         6 => ['level'=>6 , 'performance'=>300000,'ratio' => 6 ],
         7 => ['level'=>7 , 'performance'=>500000,'ratio' => 7 ],
         8 => ['level'=>8 , 'performance'=>1000000,'ratio' => 8 ],
         9 => ['level'=>9 , 'performance'=>2000000,'ratio' => 9 ],
         10 => ['level'=>10 , 'performance'=>3000000,'ratio' => 10 ],
         11 => ['level'=>11 , 'performance'=>4000000,'ratio' => 11 ],
         12 => ['level'=>12 , 'performance'=>5000000,'ratio' => 12 ],
         13 => ['level'=>13 , 'performance'=>6000000,'ratio' => 13 ],
         14 => ['level'=>14 , 'performance'=>7000000,'ratio' => 14 ],
         15 => ['level'=>15 , 'performance'=>8000000,'ratio' => 15 ],
     ];

     // 检查并更新会员等级
     public static function checkLevel(Member $member){
         $currentPerformance = (float) $member->performance;
         $currentLevel = $member->level;
         $newLevel = $currentLevel;

         // 遍历所有等级，找出符合的最高等级
         foreach (self::LEVEL as $levelInfo) {
             $requirement = (float) $levelInfo['performance'];
             // 如果业绩达到要求，且等级高于当前等级
             if ($currentPerformance >= $requirement && $levelInfo['level'] > $currentLevel) {
                 $newLevel = $levelInfo['level'];
             }
         }

         // 如果等级有变化，更新数据库
         if ($newLevel > $currentLevel) {
             $member->level = $newLevel;
             $member->set_level = 1;
             $member->save();
         }

         return $newLevel;
     }


    public static function createMember($address)
    {
        $address = strtolower($address);
        $pid = 0;
        $deep = 0;
        $path = '';
        $member = Member::create([
            'pid' => $pid,
            'deep' => $deep,
            'path' => $path,
            'address' => $address,
            'level' => 0,
            'performance' => 0,
            'total_earnings' => 0,
            'total_consumption' => 0,
            'total_grab_count' => 0,
        ]);
        return $member;
    }
}
