<?php

use App\Models\VideoCategory;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Dcat\Admin\Admin;

Admin::routes();

Route::group([
    'prefix'     => config('admin.route.prefix'),
    'namespace'  => config('admin.route.namespace'),
    'middleware' => config('admin.route.middleware'),
], function (Router $router) {

    $router->get('/', 'HomeController@index');
    // NOTE: WeChatAccountController 当前不存在，会导致 `php artisan route:list` 反射失败。
    // 该功能与本项目 Polymarket 跟单无关，先注释掉，后续如需再补齐控制器。
    // $router->resource('we-chat-account', \App\Admin\Controllers\WeChatAccountController::class);




    $router->resource('pm-auth-nonces', PmAuthNonceController::class);
    $router->resource('pm-copy-tasks', PmCopyTaskController::class);
    $router->resource('pm-custody-transfer-requests', PmCustodyTransferRequestController::class);
    $router->resource('pm-custody-wallets', PmCustodyWalletController::class);
    $router->resource('pm-leaders', PmLeaderController::class);
    $router->resource('pm-leader-trades', PmLeaderTradeController::class);
    $router->resource('pm-members', PmMemberController::class);
    $router->resource('pm-orders', PmOrderController::class);
    $router->resource('pm-order-intents', PmOrderIntentController::class);
    $router->resource('pm-polymarket-api-credentials', PmPolymarketApiCredentialController::class);
    $router->resource('pm-portfolio-snapshots', PmPortfolioSnapshotController::class);



});
