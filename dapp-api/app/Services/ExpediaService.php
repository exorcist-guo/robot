<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Redis;

class ExpediaService
{
    const TOKEN_REDIS = 'expedia_token';

    public static function getToken()
    {
        $token = Redis::get(self::TOKEN_REDIS);
        if(!$token){
            $token = self::createToken();
        }
        return $token;
    }

    public static function createToken(){

        $basic = base64_encode(config('expedia.key').':'.config('expedia.secret'));
        $config = ['headers'=>[
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => 'Basic '.$basic,
            ],
            'base_uri' => config('expedia.url'),
        ];
        var_dump($config);
        $client = new Client($config);
        $res = $client->post( '/ean-services/rs/hotel/v3/session', ['grant_type'=>'client_credentials']);
        var_dump($res);
    }
}
