<?php

namespace App\Providers;

use App\Models\AiSetting;
use App\Models\Comment;
use App\Observers\CommentObserver;
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

        Comment::observe(CommentObserver::class);

        // Force HTTPS in production — TLS terminates at the reverse proxy (Traefik/Caddy),
        // so Laravel sees plain HTTP and generates http:// URLs without this.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Push DB-driven AI settings into config so SummaryManager reads the
        // correct provider/key/model without knowing about AiSetting directly.
        // This runs after migrations in production; in tests, RefreshDatabase
        // resets the DB and a fresh AiSetting::current() row is created with
        // the .env defaults (provider=rules), while unit tests override config
        // via Config::set() after boot — both paths work correctly.
        $this->bootAiSettings();
    }

    /**
     * Populate summary config from the DB ai_settings row.
     *
     * Wrapped in a try/catch so that artisan commands that run before migrations
     * (e.g. `artisan optimize`) do not crash when the table does not yet exist.
     */
    private function bootAiSettings(): void
    {
        try {
            $settings = AiSetting::current();

            config([
                'summary.default' => $settings->effective_driver,
                'summary.drivers.llm.base_url' => $settings->effective_base_url,
                'summary.drivers.llm.api_key' => $settings->api_key,
                'summary.drivers.llm.model' => $settings->model,
            ]);
        } catch (\Throwable) {
            // Table does not exist yet (pre-migration environment) — use .env defaults.
        }
    }
}
