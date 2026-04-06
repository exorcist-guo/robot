<?php


namespace App\Traits;


use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

trait ApiResponseTrait
{
    protected function success($msg = '', $data = [])
    {
        return $this->message($msg, $data, 0);
    }

    protected function error($msg = '', $data = [], $code = 1)
    {
        return $this->message($msg, $data, $code);
    }

    protected function message($msg, $data = [], $code = 0)
    {
        return response()->json(compact('msg', 'code', 'data'));
    }
}
