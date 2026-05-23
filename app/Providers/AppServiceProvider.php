<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Force HTTPS in production — TLS terminates at the reverse proxy (Traefik/Caddy),
        // so Laravel sees plain HTTP and generates http:// URLs without this.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
