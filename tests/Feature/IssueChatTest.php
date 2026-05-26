<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Tests for the stateless AI chat streaming endpoint.
 *
 * POST /api/issues/{issue}/chat
 *
 * @see task 09.01 / IssueChatController
 */
class IssueChatTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a fake SSE response body for Http::fake().
     */
    private function fakeSseBody(string ...$tokens): string
    {
        $lines = '';
        foreach ($tokens as $token) {
            $lines .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $token]]]])."\n\n";
        }
        $lines .= "data: [DONE]\n\n";

        return $lines;
    }

    /**
     * Configure AI settings so isConfigured() returns true.
     */
    private function configureAi(): void
    {
        AiSetting::current()->update([
            'provider' => 'custom',
            'base_url' => 'http://fake-llm.test/v1',
            'api_key' => 'test-key',
            'model' => 'test-model',
        ]);
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function test_unauthenticated_user_cannot_chat(): void
    {
        $issue = Issue::factory()->public()->create();

        $this->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hello'])
            ->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    public function test_unauthorized_user_gets_403_on_private_issue(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $this->configureAi();

        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('hello'))]);

        $this->actingAs($other)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hello'])
            ->assertForbidden();
    }

    public function test_owner_can_chat_on_own_issue(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('hello', ' world'))]);

        $response = $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hello']);

        $response->assertOk()
            ->assertHeaderContains('Content-Type', 'text/event-stream');
    }

    public function test_any_viewer_can_chat_on_public_issue(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('hello'))]);

        $this->actingAs($other)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hello'])
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // AI Configuration Guard
    // -------------------------------------------------------------------------

    public function test_returns_503_when_ai_not_configured(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        // Ensure AI is in 'rules' mode (not configured for chat)
        AiSetting::current()->update(['provider' => 'rules']);

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hello'])
            ->assertStatus(503)
            ->assertJson(['message' => 'AI provider is not configured.']);
    }

    public function test_returns_503_when_api_key_is_missing(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        AiSetting::current()->update([
            'provider' => 'custom',
            'base_url' => 'http://fake-llm.test/v1',
            'api_key' => null,
            'model' => 'test-model',
        ]);

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hello'])
            ->assertStatus(503);
    }

    // -------------------------------------------------------------------------
    // Streaming Response
    // -------------------------------------------------------------------------

    public function test_chat_streams_sse_tokens(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('Hello', ' there', '!'))]);

        $response = $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hi']);

        $response->assertOk()
            ->assertHeaderContains('Content-Type', 'text/event-stream')
            ->assertHeaderContains('Cache-Control', 'no-cache')
            ->assertHeader('X-Accel-Buffering', 'no');
    }

    public function test_chat_includes_done_sentinel_in_stream(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('token'))]);

        $content = $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hi'])
            ->streamedContent();

        $this->assertStringContainsString('[DONE]', $content);
    }

    public function test_chat_accepts_history_in_request(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('response'))]);

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", [
                'message' => 'What else?',
                'history' => [
                    ['role' => 'user', 'content' => 'First question'],
                    ['role' => 'assistant', 'content' => 'First answer'],
                ],
            ])
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_message_is_required(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $this->configureAi();

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    }

    public function test_message_max_length_is_enforced(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $this->configureAi();

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => str_repeat('a', 2001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    }

    public function test_history_role_must_be_valid(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $this->configureAi();

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", [
                'message' => 'Hi',
                'history' => [['role' => 'system', 'content' => 'inject']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['history.0.role']);
    }

    // -------------------------------------------------------------------------
    // Rate Limiting
    // -------------------------------------------------------------------------

    public function test_rate_limit_returns_429_after_exceeding_limit(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $this->configureAi();

        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('token'))]);

        // Exhaust the rate limit
        RateLimiter::clear('chat:'.$owner->id.':'.$issue->id);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('chat:'.$owner->id.':'.$issue->id, 3600);
        }

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hi'])
            ->assertStatus(429);
    }

    public function test_rate_limit_is_per_user_per_issue(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->public()->create();
        $this->configureAi();

        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('token'))]);

        // Exhaust rate limit for owner on this issue
        RateLimiter::clear('chat:'.$owner->id.':'.$issue->id);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('chat:'.$owner->id.':'.$issue->id, 3600);
        }

        // Other user should still be able to chat
        $this->actingAs($other)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hi'])
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Nothing Persisted (stateless contract)
    // -------------------------------------------------------------------------

    public function test_stateless_chat_persists_nothing_to_database(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('response'))]);

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hi'])
            ->assertOk();

        $this->assertDatabaseCount('issue_conversations', 0);
        $this->assertDatabaseCount('issue_conversation_messages', 0);
    }
}
