<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\RedController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeaderController;
use App\Http\Controllers\Api\CopyTaskController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\CommunityController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\MarketController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| 所有接口统一返回: { msg, code, data }
|
*/

// 旧项目接口（暂保留，后续可移除/迁移）
Route::get('/rule', [RedController::class, 'rule']);
Route::get('/participate/records', [RedController::class, 'participateRecords']);

// 新：钱包登录
Route::prefix('auth')->group(function () {
    Route::post('/nonce', [AuthController::class, 'nonce']);
    Route::post('/login', [AuthController::class, 'login']);
});

// 新：受保护接口（后续逐步补齐 home / leaders / copy-tasks / wallet / community / me 等）
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/home', [HomeController::class, 'index']);

    // leader
    Route::get('/leaders', [LeaderController::class, 'index']);
    Route::post('/leaders/resolve', [LeaderController::class, 'resolve']);

    // markets
    Route::post('/markets/resolve', [MarketController::class, 'resolve']);

    // copy tasks
    Route::get('/copy-tasks', [CopyTaskController::class, 'index']);
    Route::post('/copy-tasks', [CopyTaskController::class, 'store']);
    Route::put('/copy-tasks/{id}', [CopyTaskController::class, 'update']);
    Route::delete('/copy-tasks/{id}', [CopyTaskController::class, 'destroy']);
    Route::post('/copy-tasks/{id}/pause', [CopyTaskController::class, 'pause']);
    Route::post('/copy-tasks/{id}/resume', [CopyTaskController::class, 'resume']);

    // wallet custody
    Route::post('/wallet/import', [WalletController::class, 'import']);
    Route::get('/wallet/status', [WalletController::class, 'status']);
    Route::post('/wallet/sub/create', [WalletController::class, 'createSubWallet']);
    Route::get('/wallet/sub/status', [WalletController::class, 'custodyStatus']);
    Route::post('/wallet/transfer/prepare', [WalletController::class, 'prepareTransfer']);
    Route::post('/wallet/transfer/submit', [WalletController::class, 'submitTransfer']);
    Route::get('/wallet/transfer/{id}', [WalletController::class, 'transferDetail']);
    Route::get('/wallet/allowance-status', [WalletController::class, 'allowanceStatus']);
    Route::post('/wallet/approve', [WalletController::class, 'approve']);

    // me
    Route::get('/me', [MeController::class, 'profile']);
    Route::get('/me/records', [MeController::class, 'records']);
    Route::get('/me/records/{id}', [MeController::class, 'recordDetail']);

    // community
    Route::get('/community/summary', [CommunityController::class, 'summary']);
    Route::get('/community/records', [CommunityController::class, 'records']);

    // assets
    Route::get('/assets/positions', [AssetController::class, 'positions']);
});

