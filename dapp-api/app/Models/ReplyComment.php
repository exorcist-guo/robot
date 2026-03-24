<?php

namespace App\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;

use Illuminate\Database\Eloquent\Model;

/**
 * 
 *
 * @property int $id
 * @property int|null $comment_id
 * @property int|null $account_id
 * @property string|null $reply_content
 * @property int|null $is_successful
 * @property string|null $error_msg
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment query()
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment whereAccountId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment whereCommentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment whereErrorMsg($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment whereIsSuccessful($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment whereReplyContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReplyComment whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class ReplyComment extends Model
{
	use HasDateTimeFormatter;
    protected $table = 'reply_comment';
    
}
