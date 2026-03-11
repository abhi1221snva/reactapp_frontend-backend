<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Registers a slow-query listener in local/development environments.
     * Any query taking more than 100 ms is logged at WARNING level so
     * developers can identify and fix expensive queries before they reach
     * production.
     *
     * @return void
     */
    public function boot()
    {
        if (app()->environment('local', 'development')) {
            DB::listen(function ($query) {
                if ($query->time > 100) {
                    Log::warning('[SLOW QUERY] ' . round($query->time, 2) . 'ms: ' . $query->sql, [
                        'bindings'    => $query->bindings,
                        'connection'  => $query->connectionName,
                    ]);
                }
            });
        }
    }
}
