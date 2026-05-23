<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature tests for POST /api/issues/triage-suggest
 *
 * Covers:
 *  - Unauthenticated request returns 401
 *  - Validation: title and description required, minimums enforced
 *  - Heuristic path (rules driver): returns priority + category_id
 *  - LLM path: sends correct payload, maps response to priority + category_id
 *  - Fallback to heuristic when LLM call fails
 *  - Response shape: priority, category_id, category_name, confidence
 */
class TriageSuggestApiTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Ensure rules driver is active (default). */
    private function useRulesDriver(): void
    {
        $setting = AiSetting::current();
        $setting->provider = 'rules';
        $setting->save();
    }

    /** Switch to LLM driver. */
    private function useLlmDriver(string $model = 'gpt-4o-mini'): AiSetting
    {
        $setting = AiSetting::current();
        $setting->provider = 'openrouter';
        $setting->model = $model;
        $setting->api_key = 'test-key';
        $setting->save();
        $setting->refresh();

        return $setting;
    }

    /** Seed at least one category so heuristic has a fallback. */
    private function seedCategories(): void
    {
        Category::factory()->create(['name' => 'billing']);
        Category::factory()->create(['name' => 'technical']);
        Category::factory()->create(['name' => 'bug']);
    }

    // -------------------------------------------------------------------------
    // Auth guard
    // -------------------------------------------------------------------------

    /** Unauthenticated request must get 401. */
    public function test_unauthenticated_cannot_triage(): void
    {
        $this->postJson('/api/issues/triage-suggest', [
            'title' => 'Server is down',
            'description' => 'The entire API is returning 503 errors since 10 minutes ago.',
        ])->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /** title is required. */
    public function test_validation_requires_title(): void
    {
        $this->useRulesDriver();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'description' => 'The entire API is returning 503 errors since 10 minutes ago.',
        ])->assertStatus(422)->assertJsonValidationErrors('title');
    }

    /** description is required. */
    public function test_validation_requires_description(): void
    {
        $this->useRulesDriver();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'title' => 'Server is down',
        ])->assertStatus(422)->assertJsonValidationErrors('description');
    }

    /** title minimum length is 3. */
    public function test_validation_title_min_length(): void
    {
        $this->useRulesDriver();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'title' => 'Hi',
            'description' => 'Some description text that is long enough to pass.',
        ])->assertStatus(422)->assertJsonValidationErrors('title');
    }

    /** description minimum length is 10. */
    public function test_validation_description_min_length(): void
    {
        $this->useRulesDriver();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'title' => 'Server is down',
            'description' => 'Short',
        ])->assertStatus(422)->assertJsonValidationErrors('description');
    }

    // -------------------------------------------------------------------------
    // Heuristic (rules) path
    // -------------------------------------------------------------------------

    /** Rules driver returns a valid response shape. */
    public function test_heuristic_returns_correct_shape(): void
    {
        $this->useRulesDriver();
        $this->seedCategories();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'title' => 'Billing invoice is wrong',
            'description' => 'The invoice amount is doubled compared to my subscription plan.',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'priority',
                    'category_id',
                    'category_name',
                    'confidence',
                ],
            ]);

        $this->assertContains($response->json('data.priority'), ['low', 'medium', 'high', 'critical']);
        $this->assertNotNull($response->json('data.category_id'));
    }

    /** Critical keywords map to critical priority. */
    public function test_heuristic_maps_critical_keywords(): void
    {
        $this->useRulesDriver();
        $this->seedCategories();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'title' => 'Critical outage — server is down',
            'description' => 'Complete outage detected. Data loss imminent. Emergency response required.',
        ]);

        $response->assertStatus(200);
        $this->assertSame('critical', $response->json('data.priority'));
    }

    /** Billing keywords map to billing category. */
    public function test_heuristic_maps_billing_category(): void
    {
        $this->useRulesDriver();
        $this->seedCategories();

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'title' => 'Billing invoice problem',
            'description' => 'My invoice amount is doubled. Payment was charged twice. Please refund.',
        ]);

        $response->assertStatus(200);
        $this->assertSame('billing', $response->json('data.category_name'));
        $this->assertSame('heuristic', $response->json('data.confidence'));
    }

    /** When no category matches, falls back to first category in DB. */
    public function test_heuristic_falls_back_to_first_category(): void
    {
        $this->useRulesDriver();
        $first = Category::factory()->create(['name' => 'general']);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'title' => 'Something strange happened',
            'description' => 'An unusual event occurred in the system that does not fit any category.',
        ]);

        $response->assertStatus(200);
        $this->assertSame($first->id, $response->json('data.category_id'));
    }

    // -------------------------------------------------------------------------
    // LLM path
    // -------------------------------------------------------------------------

    /** LLM driver sends request to the AI endpoint and maps the response. */
    public function test_llm_driver_returns_ai_suggestion(): void
    {
        $this->seedCategories();
        $settings = $this->useLlmDriver();

        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode(['priority' => 'high', 'category' => 'technical']),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'title' => 'API response latency spiked',
            'description' => 'Database queries are timing out causing 10x latency across all endpoints.',
        ]);

        $response->assertStatus(200);
        $this->assertSame('high', $response->json('data.priority'));
        $this->assertSame('technical', $response->json('data.category_name'));
        $this->assertSame('ai', $response->json('data.confidence'));
    }

    /** LLM failure falls back to heuristic — still returns 200. */
    public function test_llm_failure_falls_back_to_heuristic(): void
    {
        $this->seedCategories();
        $this->useLlmDriver();

        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'title' => 'Billing invoice is wrong',
            'description' => 'The invoice amount is doubled compared to my subscription plan.',
        ]);

        $response->assertStatus(200);
        // Must fall back to heuristic confidence label
        $this->assertSame('heuristic', $response->json('data.confidence'));
    }

    /** LLM invalid JSON response falls back to heuristic. */
    public function test_llm_invalid_json_falls_back_to_heuristic(): void
    {
        $this->seedCategories();
        $this->useLlmDriver();

        Http::fake([
            '*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'not valid json at all',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues/triage-suggest', [
            'title' => 'Billing invoice is wrong',
            'description' => 'The invoice amount is doubled. Please check and issue a refund immediately.',
        ]);

        $response->assertStatus(200);
        $this->assertSame('heuristic', $response->json('data.confidence'));
    }
}
