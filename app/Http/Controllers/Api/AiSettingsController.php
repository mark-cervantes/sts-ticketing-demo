<?php

namespace App\Http\Controllers\Api;

use App\Enums\Priority;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAiSettingRequest;
use App\Models\AiSetting;
use App\Models\Category;
use App\Models\Issue;
use App\Models\User;
use App\Services\Summary\Drivers\LlmDriver;
use App\Services\Summary\Drivers\RulesDriver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Manages AI configuration settings.
 *
 * GET    /api/settings/ai          — show current settings (api_key masked)
 * PUT    /api/settings/ai          — update settings (supports preset shorthand)
 * POST   /api/settings/ai/test     — test the current config with a dummy issue
 * GET    /api/settings/ai/models   — proxy OpenRouter model list (cached 1h)
 * GET    /api/settings/ai/presets  — list available server-side presets (no keys)
 *
 * @see Task 08.01
 */
class AiSettingsController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/settings/ai
    // -------------------------------------------------------------------------

    /**
     * Return current AI settings with the api_key masked.
     *
     * The raw api_key is NEVER returned. Only api_key_set (bool) and
     * api_key_masked (first 10 chars + '***') are exposed.
     */
    public function show(): JsonResponse
    {
        $settings = AiSetting::current();
        $settings->load('updatedBy');

        return response()->json([
            'data' => $this->formatSettings($settings),
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /api/settings/ai
    // -------------------------------------------------------------------------

    /**
     * Update the AI settings.
     *
     * Supports two modes:
     *   1. Preset mode: { "preset": "openrouter" } — resolves all fields from
     *      config/ai-presets.php; api_key never comes from the frontend.
     *   2. Manual mode: { "provider": "custom", "base_url": "...", ... } — the
     *      existing field-by-field update behaviour.
     *
     * api_key is only written when a non-empty string is provided (manual mode).
     * An empty / null api_key in the request preserves the existing key.
     */
    public function update(UpdateAiSettingRequest $request): JsonResponse
    {
        $settings = AiSetting::current();
        $validated = $request->validated();

        // ── Preset mode ──────────────────────────────────────────────────────
        if (isset($validated['preset'])) {
            /** @var array{label:string,description:string,provider:string,base_url:string,model:string,api_key:string|null}|null $preset */
            $preset = config('ai-presets.'.$validated['preset']);

            if ($preset === null) {
                return response()->json([
                    'message' => 'The selected preset is not available.',
                    'errors' => ['preset' => ['Unknown preset: '.$validated['preset']]],
                ], 422);
            }

            if (empty($preset['api_key'])) {
                return response()->json([
                    'message' => 'The selected preset is not configured (missing API key).',
                    'errors' => ['preset' => ['Preset "'.$validated['preset'].'" has no API key configured on this server.']],
                ], 422);
            }

            $settings->provider = $preset['provider'];
            $settings->base_url = $preset['base_url'] ?: null;
            // Allow an optional model override — if not provided (or null), use the preset default.
            $settings->model = (isset($validated['model']) && $validated['model'] !== '')
                ? $validated['model']
                : ($preset['model'] ?: null);
            $settings->api_key = $preset['api_key'];
            $settings->active_preset = $validated['preset'];
            $settings->updated_by = $request->user()->id;
            $settings->save();
            $settings->load('updatedBy');

            Cache::forget('ai_settings.models.openrouter');
            Artisan::call('queue:restart');

            return response()->json([
                'data' => $this->formatSettings($settings),
            ]);
        }

        // ── Manual / custom mode ─────────────────────────────────────────────
        $settings->provider = $validated['provider'];

        if (array_key_exists('base_url', $validated)) {
            $settings->base_url = $validated['base_url'] ?: null;
        }

        if (array_key_exists('model', $validated)) {
            $settings->model = $validated['model'] ?: null;
        }

        // Only overwrite api_key when a non-empty string is passed.
        $incomingKey = $validated['api_key'] ?? null;

        if (is_string($incomingKey) && $incomingKey !== '') {
            $settings->api_key = $incomingKey;
        }

        // Manual update clears any active preset.
        $settings->active_preset = null;
        $settings->updated_by = $request->user()->id;
        $settings->save();

        $settings->load('updatedBy');

        // Flush cached model list when settings change.
        Cache::forget('ai_settings.models.openrouter');

        // Queue workers cache config at boot time. After changing AI settings,
        // signal all workers to restart so they pick up the new DB values
        // via AppServiceProvider::bootAiSettings() on their next boot cycle.
        Artisan::call('queue:restart');

        return response()->json([
            'data' => $this->formatSettings($settings),
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/settings/ai/presets
    // -------------------------------------------------------------------------

    /**
     * Return the list of server-side AI presets.
     *
     * api_key is intentionally OMITTED from every entry — keys live server-side only.
     * The `configured` flag indicates whether the env var has a value.
     */
    public function presets(): JsonResponse
    {
        /** @var array<string, array{label:string,description:string,provider:string,base_url:string,model:string,api_key:string|null}> $presets */
        $presets = config('ai-presets', []);

        $data = collect($presets)
            ->map(fn (array $preset, string $key): array => [
                'key' => $key,
                'label' => $preset['label'],
                'description' => $preset['description'],
                'model' => $preset['model'],
                'provider' => $preset['provider'],
                'configured' => ! empty($preset['api_key']),
            ])
            ->values()
            ->all();

        return response()->json(['data' => $data]);
    }

    // -------------------------------------------------------------------------
    // POST /api/settings/ai/test
    // -------------------------------------------------------------------------

    /**
     * Test the current AI configuration using a synthetic issue.
     *
     * Accepts optional request-body overrides (provider/base_url/api_key/model)
     * so the user can test a configuration before saving it.
     *
     * Returns 422 on failure with the error message.
     */
    public function test(Request $request): JsonResponse
    {
        $settings = AiSetting::current();

        // ── Preset shorthand ─────────────────────────────────────────────────
        // When the frontend sends { preset: "openrouter" }, resolve the preset
        // config and use its values as overrides — identical to how update() works,
        // but without persisting anything.
        if ($request->filled('preset')) {
            $presetKey = $request->input('preset');

            /** @var array{label:string,description:string,provider:string,base_url:string,model:string,api_key:string|null}|null $preset */
            $preset = config('ai-presets.'.$presetKey);

            if ($preset === null) {
                return response()->json(['error' => 'Unknown preset: '.$presetKey], 422);
            }

            if (empty($preset['api_key'])) {
                return response()->json(['error' => 'Preset "'.$presetKey.'" has no API key configured on this server.'], 422);
            }

            $overrideProvider = $preset['provider'];
            $overrideBaseUrl = $preset['base_url'] ?: $settings->effective_base_url;
            $overrideApiKey = $preset['api_key'];
            // Allow a model override from the request body (same as update()).
            $overrideModel = $request->filled('model') ? $request->input('model') : ($preset['model'] ?: $settings->model);
        } else {
            // Allow temporary overrides from the request body (test-before-save).
            $overrideProvider = $request->input('provider', $settings->provider);
            $overrideBaseUrl = $request->input('base_url', $settings->effective_base_url);
            $overrideApiKey = $request->input('api_key', $settings->api_key);
            $overrideModel = $request->input('model', $settings->model);
        }

        $effectiveDriver = $overrideProvider === 'rules' ? 'rules' : 'llm';

        if ($effectiveDriver === 'rules') {
            // Rules driver needs no external calls — create a synthetic issue object.
            $issue = $this->makeSyntheticIssue();

            try {
                $driver = new RulesDriver;
                $result = $driver->generate($issue);
            } catch (\Throwable $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return response()->json([
                'driver' => 'rules',
                'summary' => $result->summary,
                'suggested_next_action' => $result->suggestedNextAction,
            ]);
        }

        // LLM driver — push overrides into config temporarily.
        config([
            'summary.drivers.llm.base_url' => $overrideBaseUrl,
            'summary.drivers.llm.api_key' => $overrideApiKey,
            'summary.drivers.llm.model' => $overrideModel,
            'summary.drivers.llm.timeout' => 30,
        ]);

        $issue = $this->makeSyntheticIssue();

        try {
            $driver = new LlmDriver(app(HttpFactory::class));
            $result = $driver->generate($issue);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'driver' => 'llm',
            'summary' => $result->summary,
            'suggested_next_action' => $result->suggestedNextAction,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/settings/ai/models
    // -------------------------------------------------------------------------

    /**
     * Proxy the model list for the configured provider (or a named preset).
     *
     * Supports an optional `?preset=<key>` query param so the frontend can
     * fetch models for a preset before saving it.  Without the param the saved
     * DB settings are used (existing behaviour).
     *
     * Supported providers:
     *  - openrouter  → GET https://openrouter.ai/api/v1/models
     *  - ollama-cloud preset (custom provider with ollama base_url) → GET {base}/api/tags
     *
     * Results are cached for 1 hour per provider/preset.
     */
    public function models(Request $request): JsonResponse
    {
        $settings = AiSetting::current();

        // ── Resolve provider, key, and base_url ──────────────────────────────
        $presetKey = $request->query('preset');

        if ($presetKey) {
            /** @var array{label:string,description:string,provider:string,base_url:string,model:string,api_key:string|null}|null $preset */
            $preset = config('ai-presets.'.$presetKey);

            if ($preset === null || empty($preset['api_key'])) {
                return response()->json(['error' => 'Preset not configured.'], 422);
            }

            $provider = $preset['provider'];
            $apiKey = $preset['api_key'];
            $baseUrl = $preset['base_url'] ?? null;
        } else {
            $provider = $settings->provider;
            $apiKey = $settings->api_key;
            $baseUrl = $settings->base_url;
        }

        // ── OpenRouter ────────────────────────────────────────────────────────
        if ($provider === 'openrouter') {
            if (empty($apiKey)) {
                return response()->json(['error' => 'An API key is required to fetch the OpenRouter model list.'], 422);
            }

            $cacheKey = 'ai_settings.models.openrouter';

            $models = Cache::remember($cacheKey, 3600, function () use ($apiKey): mixed {
                $response = Http::withToken((string) $apiKey)
                    ->timeout(15)
                    ->get('https://openrouter.ai/api/v1/models');

                if ($response->failed()) {
                    return null;
                }

                return $response->json();
            });

            if ($models === null) {
                Cache::forget($cacheKey);

                return response()->json(['error' => 'Failed to fetch models from OpenRouter.'], 422);
            }

            return response()->json($models);
        }

        // ── Ollama-compatible (custom provider with /v1 base_url) ─────────────
        if ($provider === 'custom' && ! empty($baseUrl)) {
            // Strip /v1 suffix to get the Ollama base, then call /api/tags.
            $ollamaBase = rtrim(preg_replace('#/v1/?$#', '', (string) $baseUrl), '/');
            $cacheKey = 'ai_settings.models.ollama.'.md5($ollamaBase);

            $models = Cache::remember($cacheKey, 3600, function () use ($ollamaBase, $apiKey): mixed {
                $http = Http::timeout(15);

                if (! empty($apiKey)) {
                    $http = $http->withToken((string) $apiKey);
                }

                $response = $http->get("$ollamaBase/api/tags");

                if ($response->failed()) {
                    return null;
                }

                // Ollama returns { models: [{ name: "gemma3:4b", ... }] }.
                // Normalise to the same shape as OpenRouter { data: [...] }.
                $ollamaModels = $response->json('models', []);

                return [
                    'data' => collect($ollamaModels)
                        ->map(fn (array $m): array => [
                            'id' => $m['name'],
                            'name' => $m['name'],
                            'context_length' => 0,
                            'pricing' => ['prompt' => '0', 'completion' => '0'],
                        ])
                        ->values()
                        ->all(),
                ];
            });

            if ($models === null) {
                Cache::forget($cacheKey);

                return response()->json(['error' => 'Failed to fetch models from Ollama endpoint.'], 422);
            }

            return response()->json($models);
        }

        return response()->json(['error' => 'Model listing is only available for the openrouter provider.'], 422);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Format AiSetting for a JSON response.
     * api_key is masked — raw value is never exposed.
     *
     * @return array<string, mixed>
     */
    private function formatSettings(AiSetting $settings): array
    {
        $apiKey = $settings->api_key;
        $apiKeySet = ! empty($apiKey);
        $apiKeyMasked = null;

        if ($apiKeySet && is_string($apiKey)) {
            $apiKeyMasked = mb_substr($apiKey, 0, 10).'***';
        }

        return [
            'provider' => $settings->provider,
            'base_url' => $settings->base_url,
            'api_key_set' => $apiKeySet,
            'api_key_masked' => $apiKeyMasked,
            'model' => $settings->model,
            'active_preset' => $settings->active_preset,
            'updated_by' => $settings->updatedBy ? [
                'id' => $settings->updatedBy->id,
                'name' => $settings->updatedBy->name,
            ] : null,
            'updated_at' => $settings->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Build a synthetic Issue-like object for test calls.
     *
     * Uses a real Category model when one exists in the DB; otherwise creates
     * an anonymous object so the driver can still run without DB dependencies.
     */
    private function makeSyntheticIssue(): Issue
    {
        /** @var Category|null $category */
        $category = Category::first();

        /** @var User $user */
        $user = User::first() ?? User::factory()->create();

        return Issue::factory()
            ->for($user)
            ->for($category ?? Category::factory()->create())
            ->make([
                'title' => 'Test issue: payment processing failure',
                'description' => 'Customer reports that their credit card payment fails at checkout with error code 4012. The issue started after the last deployment on May 20th.',
                'priority' => Priority::High,
            ]);
    }
}
