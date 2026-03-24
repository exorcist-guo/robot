<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Pm\GammaClient;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class MarketController extends Controller
{
    use ApiResponseTrait;

    public function resolve(Request $request, GammaClient $gamma)
    {
        $market = $gamma->resolveTailSweepMarket((string) $request->input('input', ''));
        if (!$market) {
            return $this->error('未找到对应市场');
        }

        return $this->success('ok', ['market' => $market]);
    }
}
