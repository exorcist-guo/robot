<?php

namespace App\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;

use Illuminate\Database\Eloquent\Model;

/**
 * 
 *
 * @property int $id
 * @property int $task_id 主任务ID
 * @property int $subtask_id 子任务ID
 * @property string $nickname 昵称
 * @property int $is_video 0 否 1是视屏号
 * @property string $comment 评论
 * @property string|null $place 位子
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|Comment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Comment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Comment query()
 * @method static \Illuminate\Database\Eloquent\Builder|Comment whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Comment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Comment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Comment whereIsVideo($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Comment whereNickname($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Comment wherePlace($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Comment whereSubtaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Comment whereTaskId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Comment whereUpdatedAt($value)
 * @property string|null $time
 * @method static \Illuminate\Database\Eloquent\Builder|Comment whereTime($value)
 * @mixin \Eloquent
 */
class Comment extends Model
{
	use HasDateTimeFormatter;
    protected $table = 'comment';

    public static function getRandomValue($array)
    {
        if (!is_array($array) || empty($array)) {
            return ''; // 如果不是数组或数组为空，返回 null
        }

        $randomKey = array_rand($array); // 获取随机键名
        return $array[$randomKey]; // 返回对应的值
    }

}
