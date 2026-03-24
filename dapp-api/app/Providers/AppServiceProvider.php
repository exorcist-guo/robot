<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Ll\DcatConfig\LlConfig;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
        $this->addAcceptableJsonType();
        if ($this->app->environment('local')) {

            $this->app->register(TelescopeServiceProvider::class);
        }

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        LlConfig::load();
    }

    protected function addAcceptableJsonType()
    {
        $this->app->rebinding('request', function ($app, $request) {
            if ($request->is('api/*')) {
                $accept = $request->header('Accept');

                if (! \Str::contains($accept, ['/json', '+json'])) {
                    $accept = rtrim('application/json,'.$accept, ',');

                    $request->headers->set('Accept', $accept);
                    $request->server->set('HTTP_ACCEPT', $accept);
                    $_SERVER['HTTP_ACCEPT'] = $accept;
                }
            }
        });
    }
}
