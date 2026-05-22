<?php

namespace Tests\Unit\Summary;

use App\Services\Summary\SummaryManager;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * SRS §7.2 — SummaryManager: driver resolution and auto-fallback.
 *
 * No DB, no HTTP — config() set in setUp() to ensure hermeticity.
 *
 * The SummaryManager class does not exist yet (task 02.04.00).
 * These tests define the required behaviour; they will fail until the
 * implementation is created.
 */
class SummaryManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('summary.default', 'rules');
        Config::set('summary.drivers.llm.base_url', 'http://llm.example.test');
        Config::set('summary.drivers.llm.api_key', null);
        Config::set('summary.drivers.llm.model', 'gpt-test');
        Config::set('summary.drivers.llm.timeout', 30);
        Config::set('summary.drivers.rules', []);
    }

    /** SRS §7.2: getDefaultDriver() returns the value from config('summary.default'). */
    public function test_get_default_driver_returns_config_default(): void
    {
        Config::set('summary.default', 'rules');

        $manager = $this->app->make(SummaryManager::class);

        $this->assertSame('rules', $manager->getDefaultDriver());
    }

    /** SRS §7.2: default driver resolves to a RulesDriver instance when config is 'rules'. */
    public function test_resolves_rules_driver_when_default_is_rules(): void
    {
        Config::set('summary.default', 'rules');

        $manager = $this->app->make(SummaryManager::class);

        $driver = $manager->driver();

        $this->assertInstanceOf(\App\Services\Summary\Drivers\RulesDriver::class, $driver);
    }

    /**
     * SRS §7.2 / SRS §7.2 line 353: auto-fallback — when default is 'llm' but
     * no API key is configured, the manager silently returns the rules driver.
     */
    public function test_auto_fallback_to_rules_when_llm_selected_but_no_api_key(): void
    {
        Config::set('summary.default', 'llm');
        Config::set('summary.drivers.llm.api_key', null);

        $manager = $this->app->make(SummaryManager::class);

        // getDefaultDriver() must return 'rules' when api_key is absent
        $this->assertSame('rules', $manager->getDefaultDriver());
    }

    /**
     * SRS §7.2: auto-fallback does NOT trigger when the api_key is present.
     * Driver resolution stays as 'llm'.
     */
    public function test_no_fallback_when_llm_api_key_is_present(): void
    {
        Config::set('summary.default', 'llm');
        Config::set('summary.drivers.llm.api_key', 'sk-test-key-abc123');

        $manager = $this->app->make(SummaryManager::class);

        $this->assertSame('llm', $manager->getDefaultDriver());
    }

    /** SRS §7.2: empty-string api_key also triggers fallback (treated as absent). */
    public function test_auto_fallback_when_api_key_is_empty_string(): void
    {
        Config::set('summary.default', 'llm');
        Config::set('summary.drivers.llm.api_key', '');

        $manager = $this->app->make(SummaryManager::class);

        $this->assertSame('rules', $manager->getDefaultDriver());
    }
}
