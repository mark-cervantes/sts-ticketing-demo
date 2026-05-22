<?php

namespace App\Providers;

use App\Services\Summary\SummaryManager;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the AI summary subsystem with the Laravel service container.
 *
 * @see SRS §7.2 / ADR-002
 */
class SummaryServiceProvider extends ServiceProvider
{
    /**
     * Register the SummaryManager as a singleton, bound to its class name
     * so the Facade accessor resolves correctly.
     */
    public function register(): void
    {
        $this->app->singleton(SummaryManager::class, function ($app): SummaryManager {
            return new SummaryManager($app);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
