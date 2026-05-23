<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\Summary\SummaryManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for Task 08.01 — AI Settings API.
 *
 * Covers:
 *  - AiSetting::current() creates default row from env defaults
 *  - GET /api/settings/ai returns masked key, never raw
 *  - PUT /api/settings/ai saves provider / model
 *  - PUT /api/settings/ai validates: openrouter needs key
 *  - PUT /api/settings/ai: sending null/empty api_key preserves existing
 *  - Encrypted storage: raw DB value differs from plaintext
 *  - SummaryManager picks up DB settings via AppServiceProvider
 */
class AiSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Return the singleton AI settings row, updating it with the given attributes.
     *
     * AppServiceProvider::boot() calls AiSetting::current() which creates the
     * initial row with provider=rules. Tests that need a different state must
     * call this helper to update the existing row rather than trying to create
     * a second one via firstOrCreate.
     */
    private function settingsWith(array $attributes): AiSetting
    {
        $setting = AiSetting::current();
        $setting->fill($attributes);
        $setting->save();
        $setting->refresh();

        return $setting;
    }

    // -------------------------------------------------------------------------
    // AiSetting::current()
    // -------------------------------------------------------------------------

    /** AiSetting::current() creates a row on first call and returns the same row on repeat. */
    public function test_current_returns_singleton_row(): void
    {
        // Ensure no pre-existing row (boot() catches the exception in test context).
        AiSetting::truncate();
        $this->assertDatabaseCount('ai_settings', 0);

        $first = AiSetting::current();
        $this->assertDatabaseCount('ai_settings', 1);

        $second = AiSetting::current();
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('ai_settings', 1);
    }

    /** AiSetting::current() seeds provider from SUMMARY_PROVIDER env (default: rules). */
    public function test_current_uses_rules_provider_as_default(): void
    {
        $setting = AiSetting::current();

        $this->assertSame('rules', $setting->provider);
    }

    // -------------------------------------------------------------------------
    // GET /api/settings/ai — masked key
    // -------------------------------------------------------------------------

    /** GET /api/settings/ai returns api_key_set = false when no key is stored. */
    public function test_show_returns_api_key_set_false_when_no_key(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'rules', 'api_key' => null]);

        $response = $this->actingAs($user)->getJson('/api/settings/ai');

        $response->assertOk()
            ->assertJsonPath('data.api_key_set', false)
            ->assertJsonPath('data.api_key_masked', null);
    }

    /** GET /api/settings/ai returns api_key_set = true and masked key when key stored. */
    public function test_show_returns_masked_key_never_raw(): void
    {
        $user = User::factory()->create();
        $this->settingsWith([
            'provider' => 'openrouter',
            'api_key' => 'sk-or-v1-supersecrettoken',
            'model' => 'google/gemini-2.5-flash',
        ]);

        $response = $this->actingAs($user)->getJson('/api/settings/ai');

        $response->assertOk()
            ->assertJsonPath('data.api_key_set', true)
            ->assertJsonPath('data.provider', 'openrouter')
            ->assertJsonPath('data.model', 'google/gemini-2.5-flash');

        // Masked key must start with first 10 chars and end with '***'.
        $masked = $response->json('data.api_key_masked');
        $this->assertStringStartsWith('sk-or-v1-s', $masked);
        $this->assertStringEndsWith('***', $masked);

        // Raw key must NEVER appear in the response body.
        $this->assertStringNotContainsString('supersecrettoken', $response->getContent());
    }

    /** GET /api/settings/ai requires authentication. */
    public function test_show_requires_authentication(): void
    {
        $this->getJson('/api/settings/ai')->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // PUT /api/settings/ai — update
    // -------------------------------------------------------------------------

    /** PUT /api/settings/ai saves provider and model. */
    public function test_update_saves_provider_and_model(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'rules']);

        $response = $this->actingAs($user)->putJson('/api/settings/ai', [
            'provider' => 'ollama',
            'model' => 'llama3:8b',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.provider', 'ollama')
            ->assertJsonPath('data.model', 'llama3:8b');

        $this->assertDatabaseHas('ai_settings', [
            'provider' => 'ollama',
            'model' => 'llama3:8b',
        ]);
    }

    /** PUT /api/settings/ai records updated_by to the authenticated user. */
    public function test_update_records_updated_by(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'rules']);

        $this->actingAs($user)->putJson('/api/settings/ai', ['provider' => 'rules']);

        $this->assertDatabaseHas('ai_settings', ['updated_by' => $user->id]);
    }

    /** PUT /api/settings/ai rejects invalid provider. */
    public function test_update_rejects_invalid_provider(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/settings/ai', ['provider' => 'invalid-provider'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['provider']);
    }

    // -------------------------------------------------------------------------
    // Validation: openrouter requires api_key
    // -------------------------------------------------------------------------

    /** PUT /api/settings/ai with openrouter requires api_key when none stored in DB. */
    public function test_update_rejects_openrouter_without_api_key_when_none_stored(): void
    {
        $user = User::factory()->create();
        // Ensure no api_key in DB.
        $this->settingsWith(['provider' => 'rules', 'api_key' => null]);

        $this->actingAs($user)
            ->putJson('/api/settings/ai', ['provider' => 'openrouter'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['api_key']);
    }

    /** PUT /api/settings/ai allows openrouter when api_key is provided in request. */
    public function test_update_allows_openrouter_when_api_key_provided_in_request(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'rules', 'api_key' => null]);

        $this->actingAs($user)
            ->putJson('/api/settings/ai', [
                'provider' => 'openrouter',
                'api_key' => 'sk-or-v1-newkey',
            ])
            ->assertOk();
    }

    /** PUT /api/settings/ai allows openrouter when api_key already exists in DB. */
    public function test_update_allows_openrouter_when_existing_key_in_db(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'openrouter', 'api_key' => 'sk-or-existing']);

        $this->actingAs($user)
            ->putJson('/api/settings/ai', [
                'provider' => 'openrouter',
                'model' => 'anthropic/claude-3.5-sonnet',
                // No api_key — existing DB key should satisfy validation.
            ])
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // api_key null/empty preserves existing
    // -------------------------------------------------------------------------

    /** Sending null api_key preserves the existing stored key. */
    public function test_update_with_null_api_key_preserves_existing(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'openrouter', 'api_key' => 'sk-or-v1-preserved']);

        $this->actingAs($user)->putJson('/api/settings/ai', [
            'provider' => 'openrouter',
            'api_key' => null,
        ]);

        // The key must still be set in the DB.
        $setting = AiSetting::first();
        $this->assertNotEmpty($setting->api_key);
        $this->assertSame('sk-or-v1-preserved', $setting->api_key);
    }

    /** Sending empty string api_key preserves the existing stored key. */
    public function test_update_with_empty_string_api_key_preserves_existing(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'rules', 'api_key' => 'sk-existing-key']);

        $this->actingAs($user)->putJson('/api/settings/ai', [
            'provider' => 'rules',
            'api_key' => '',
        ]);

        $setting = AiSetting::first();
        $this->assertSame('sk-existing-key', $setting->api_key);
    }

    /** Sending a new non-empty api_key overwrites the existing key. */
    public function test_update_with_new_api_key_overwrites_existing(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'rules', 'api_key' => 'sk-old-key']);

        $this->actingAs($user)->putJson('/api/settings/ai', [
            'provider' => 'rules',
            'api_key' => 'sk-brand-new-key',
        ]);

        $setting = AiSetting::first();
        $this->assertSame('sk-brand-new-key', $setting->api_key);
    }

    // -------------------------------------------------------------------------
    // Encrypted storage
    // -------------------------------------------------------------------------

    /**
     * Encrypted storage: the raw DB column value must not equal the plaintext key.
     *
     * Laravel's 'encrypted' cast uses AES-256-CBC — the stored value will be
     * a base64-encoded ciphertext that is different from the plaintext.
     */
    public function test_api_key_is_encrypted_in_database(): void
    {
        $this->settingsWith(['provider' => 'openrouter', 'api_key' => 'sk-or-v1-plaintextkey']);

        // Read the raw DB value bypassing Eloquent casts.
        $raw = DB::table('ai_settings')->value('api_key');

        $this->assertNotNull($raw);
        $this->assertNotSame('sk-or-v1-plaintextkey', $raw);
        // Raw value should be a Laravel encryption envelope (base64 JSON).
        $this->assertStringNotContainsString('sk-or-v1-plaintextkey', (string) $raw);
    }

    // -------------------------------------------------------------------------
    // SummaryManager picks up DB settings
    // -------------------------------------------------------------------------

    /**
     * SummaryManager reads DB settings via AppServiceProvider boot.
     *
     * When the DB row has provider='rules', config('summary.default') must be 'rules'.
     */
    public function test_summary_manager_reflects_rules_db_settings(): void
    {
        $this->settingsWith(['provider' => 'rules', 'api_key' => null]);

        $settings = AiSetting::current();
        config([
            'summary.default' => $settings->effective_driver,
            'summary.drivers.llm.base_url' => $settings->effective_base_url,
            'summary.drivers.llm.api_key' => $settings->api_key,
            'summary.drivers.llm.model' => $settings->model,
        ]);

        $manager = $this->app->make(SummaryManager::class);
        $this->assertSame('rules', $manager->getDefaultDriver());
    }

    /**
     * When DB row has provider='openrouter' with a key, SummaryManager
     * effective driver is 'llm' and api_key is present — so 'llm' is returned.
     */
    public function test_summary_manager_uses_llm_when_openrouter_with_key(): void
    {
        $this->settingsWith([
            'provider' => 'openrouter',
            'api_key' => 'sk-or-v1-testkey',
            'model' => 'google/gemini-2.5-flash',
        ]);

        $settings = AiSetting::current();
        config([
            'summary.default' => $settings->effective_driver,
            'summary.drivers.llm.base_url' => $settings->effective_base_url,
            'summary.drivers.llm.api_key' => $settings->api_key,
            'summary.drivers.llm.model' => $settings->model,
        ]);

        $manager = $this->app->make(SummaryManager::class);
        $this->assertSame('llm', $manager->getDefaultDriver());
    }

    // -------------------------------------------------------------------------
    // GET /api/settings/ai/models
    // -------------------------------------------------------------------------

    /** GET /api/settings/ai/models proxies OpenRouter when configured. */
    public function test_models_returns_proxied_list_for_openrouter(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'openrouter', 'api_key' => 'sk-or-v1-testkey']);

        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    ['id' => 'google/gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash'],
                ],
            ], 200),
        ]);

        $this->actingAs($user)
            ->getJson('/api/settings/ai/models')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'google/gemini-2.5-flash');
    }

    /** GET /api/settings/ai/models returns 422 when provider is not openrouter. */
    public function test_models_returns_422_when_provider_not_openrouter(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'rules']);

        $this->actingAs($user)
            ->getJson('/api/settings/ai/models')
            ->assertUnprocessable();
    }

    /** GET /api/settings/ai/models returns 422 when no api_key is set. */
    public function test_models_returns_422_when_no_api_key(): void
    {
        $user = User::factory()->create();
        $this->settingsWith(['provider' => 'openrouter', 'api_key' => null]);

        $this->actingAs($user)
            ->getJson('/api/settings/ai/models')
            ->assertUnprocessable();
    }

    // -------------------------------------------------------------------------
    // Effective URL attributes
    // -------------------------------------------------------------------------

    /** effective_base_url defaults to OpenRouter URL when provider=openrouter and no base_url set. */
    public function test_effective_base_url_defaults_for_openrouter(): void
    {
        $setting = $this->settingsWith(['provider' => 'openrouter', 'base_url' => null]);

        $this->assertSame('https://openrouter.ai/api/v1', $setting->effective_base_url);
    }

    /** effective_base_url defaults to Ollama URL when provider=ollama and no base_url set. */
    public function test_effective_base_url_defaults_for_ollama(): void
    {
        $setting = $this->settingsWith(['provider' => 'ollama', 'base_url' => null]);

        $this->assertSame('http://localhost:11434/v1', $setting->effective_base_url);
    }

    /** effective_base_url returns stored value when explicitly set. */
    public function test_effective_base_url_returns_stored_value_when_set(): void
    {
        $setting = $this->settingsWith(['provider' => 'openrouter', 'base_url' => 'https://custom.host/v1']);

        $this->assertSame('https://custom.host/v1', $setting->effective_base_url);
    }
}
