<?php

namespace App\Traits;


use App\Models\WeChatAccount;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToAccount
{
    public function account()
    {
        return $this->belongsTo(WeChatAccount::class, 'account_id', 'id');
    }

    public function scopeFilterAccount(Builder $query, $accountId)
    {
        $query->where('account_id', $accountId);
    }
}
