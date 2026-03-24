<?php

namespace Ll\DcatConfig\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class LlConfig extends Model
{
    protected $table= 'llconfig';
    public function setValueAttribute($value = null)
    {
        $this->attributes['value'] = is_null($value) ? '' : $value;
    }

    protected static function booted(): void
    {
        static::saved(function () {
            Cache::delete('cache_llconfig');
        });
    }

}
