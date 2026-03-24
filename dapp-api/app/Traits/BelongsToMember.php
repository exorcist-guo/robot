<?php


namespace App\Traits;


use App\Member;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToMember
{
    public function member()
    {
        return $this->belongsTo(Member::class, 'member_id', 'id');
    }

    public function scopeFilterMember(Builder $query, $memberId)
    {
            $query->where('member_id', $memberId);
    }
}