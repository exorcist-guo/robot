<?php
namespace Ll\DcatConfig;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Ll\DcatConfig\Models\LlConfig as LlConfigModel;

class LlConfig {


    public static function load()
    {
        if (Schema::hasTable('llconfig')) {
            $cache_key = 'cache_llconfig';
            $data = Cache::get($cache_key);

            if($data){
                $data = json_decode($data,true);
            }else{
                $data = LlConfigModel::all(['name', 'value']);
                Cache::set($cache_key,json_encode($data));
            }
            foreach ($data as $config) {
                config([$config['name'] => $config['value']]);
            }

        }

    }

}
