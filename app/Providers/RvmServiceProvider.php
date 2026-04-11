<?php

namespace App\Providers;

use App\Services\Rvm\Providers\AsteriskProvider;
use App\Services\Rvm\Providers\MockProvider;
use App\Services\Rvm\Providers\PlivoProvider;
use App\Services\Rvm\Providers\RvmProviderInterface;
use App\Services\Rvm\Providers\SlybroadcastProvider;
use App\Services\Rvm\Providers\TwilioProvider;
use App\Services\Rvm\RvmComplianceService;
use App\Services\Rvm\RvmDispatchService;
use App\Services\Rvm\RvmDivertService;
use App\Services\Rvm\RvmDropService;
use App\Services\Rvm\RvmFeatureFlagService;
use App\Services\Rvm\RvmIdempotencyStore;
use App\Services\Rvm\RvmProviderRouter;
use App\Services\Rvm\RvmRateLimiter;
use App\Services\Rvm\RvmShadowService;
use App\Services\Rvm\RvmWalletService;
use App\Services\Rvm\RvmWebhookService;
use Illuminate\Support\ServiceProvider;

/**
 * RVM v2 service container bindings.
 *
 * Registers all core services as singletons so requests + queue workers
 * share the same instances (matters for the rate limiter's in-process
 * caches and the provider router's health state).
 *
 * Drivers are enabled/disabled via config/rvm.php. Disabled drivers are
 * never instantiated, which keeps dead code out of the provider list.
 */
class RvmServiceProvider extends ServiceProvider
{
    public function register()
    {
        // ── Core services as singletons ────────────────────────────────────
        $this->app->singleton(RvmIdempotencyStore::class);
        $this->app->singleton(RvmRateLimiter::class);
        $this->app->singleton(RvmWalletService::class);
        $this->app->singleton(RvmComplianceService::class);
        $this->app->singleton(RvmDispatchService::class);
        $this->app->singleton(RvmWebhookService::class);
        $this->app->singleton(RvmFeatureFlagService::class);

        // Divert service — used by the legacy SendRvmJob hook to route
        // dry_run traffic into the v2 pipeline. Depends on RvmDropService
        // which is itself a singleton (declared below).
        $this->app->singleton(RvmDivertService::class, function ($app) {
            return new RvmDivertService(
                $app->make(RvmDropService::class),
            );
        });

        // Shadow service depends on compliance + router — both singletons.
        $this->app->singleton(RvmShadowService::class, function ($app) {
            return new RvmShadowService(
                $app->make(RvmComplianceService::class),
                $app->make(RvmProviderRouter::class),
            );
        });

        // ── Provider router with all enabled drivers ──────────────────────
        $this->app->singleton(RvmProviderRouter::class, function ($app) {
            $drivers = [];

            if (config('rvm.providers.mock.enabled')) {
                $drivers[] = $app->make(MockProvider::class);
            }
            if (config('rvm.providers.asterisk.enabled')) {
                $drivers[] = $app->make(AsteriskProvider::class);
            }
            if (config('rvm.providers.twilio.enabled')) {
                $drivers[] = $app->make(TwilioProvider::class);
            }
            if (config('rvm.providers.plivo.enabled')) {
                $drivers[] = $app->make(PlivoProvider::class);
            }
            if (config('rvm.providers.slybroadcast.enabled')) {
                $drivers[] = $app->make(SlybroadcastProvider::class);
            }

            return new RvmProviderRouter($drivers);
        });

        // ── Top-level facade for controllers + jobs ───────────────────────
        $this->app->singleton(RvmDropService::class, function ($app) {
            return new RvmDropService(
                $app->make(RvmIdempotencyStore::class),
                $app->make(RvmRateLimiter::class),
                $app->make(RvmComplianceService::class),
                $app->make(RvmWalletService::class),
                $app->make(RvmDispatchService::class),
                $app->make(RvmProviderRouter::class),
            );
        });
    }

    public function boot()
    {
        $this->app->configure('rvm');
    }
}
