<?php

namespace App\Models\Pm;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Dcat\Admin\Traits\HasDateTimeFormatter;

/**
 * 
 *
 * @property int $id
 * @property string $address 钱包地址(小写)
 * @property string|null $nickname 昵称
 * @property string|null $avatar_url 头像
 * @property int|null $inviter_id 邀请人 member_id
 * @property string $path 邀请层级路径 /1/2/3/
 * @property int $deep 邀请层级深度
 * @property int $status 状态: 1=正常 0=禁用
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pm\PmCopyTask> $copyTasks
 * @property-read int|null $copy_tasks_count
 * @property-read \App\Models\Pm\PmCustodyWallet|null $custodyWallet
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pm\PmCustodyWallet> $custodyWallets
 * @property-read int|null $custody_wallets_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PmMember> $invitees
 * @property-read int|null $invitees_count
 * @property-read PmMember|null $inviter
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Pm\PmPortfolioSnapshot> $portfolioSnapshots
 * @property-read int|null $portfolio_snapshots_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember query()
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember whereAvatarUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember whereDeep($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember whereInviterId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember whereLastLoginAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember whereNickname($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|PmMember whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class PmMember extends Authenticatable
{
    use HasApiTokens, Notifiable, HasDateTimeFormatter;

    protected $table = 'pm_members';

    protected $guarded = [];

    protected $hidden = [];

    protected $casts = [
        'last_login_at' => 'datetime',
    ];

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'inviter_id');
    }

    public function invitees(): HasMany
    {
        return $this->hasMany(self::class, 'inviter_id');
    }

    public function custodyWallet(): HasOne
    {
        return $this->hasOne(PmCustodyWallet::class, 'member_id')->where('wallet_role', PmCustodyWallet::ROLE_MASTER);
    }

    public function custodyWallets(): HasMany
    {
        return $this->hasMany(PmCustodyWallet::class, 'member_id');
    }

    public function copyTasks(): HasMany
    {
        return $this->hasMany(PmCopyTask::class, 'member_id');
    }

    public function portfolioSnapshots(): HasMany
    {
        return $this->hasMany(PmPortfolioSnapshot::class, 'member_id');
    }
}
