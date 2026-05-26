<?php

namespace Tests\Feature\Ai;

use App\Models\AiSetting;
use App\Models\Category;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for ChatService streaming with tool support.
 *
 * Verifies that:
 *  - Tool definitions are included in LLM requests when tools are registered
 *  - tool_call SSE events are emitted with the correct shape
 *  - Regular content tokens continue to work alongside tool support
 *
 * @see task 09.04 / ChatService / IssueChatController::chat()
 */
class ChatServiceToolStreamTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function configureAi(): void
    {
        AiSetting::current()->update([
            'provider' => 'custom',
            'base_url' => 'http://fake-llm.test/v1',
            'api_key' => 'test-key',
            'model' => 'test-model',
        ]);
    }

    /**
     * Build a fake SSE response with regular content tokens.
     */
    private function fakeContentSse(string ...$tokens): string
    {
        $lines = '';

        foreach ($tokens as $token) {
            $lines .= 'data: '.json_encode([
                'choices' => [['delta' => ['content' => $token]]],
            ])."\n\n";
        }

        $lines .= "data: [DONE]\n\n";

        return $lines;
    }

    /**
     * Build a fake SSE response that includes a tool_calls delta (OpenAI format).
     * Arguments arrive in two fragments to test accumulation.
     */
    private function fakeToolCallSse(string $toolName, array $arguments): string
    {
        $argsJson = json_encode($arguments);
        $half = (int) (strlen($argsJson) / 2);
        $part1 = substr($argsJson, 0, $half);
        $part2 = substr($argsJson, $half);

        // Chunk 1: tool name + first half of arguments
        $chunk1 = json_encode([
            'choices' => [[
                'delta' => [
                    'tool_calls' => [[
                        'index' => 0,
                        'function' => [
                            'name' => $toolName,
                            'arguments' => $part1,
                        ],
                    ]],
                ],
            ]],
        ]);

        // Chunk 2: second half of arguments (fragmented streaming)
        $chunk2 = json_encode([
            'choices' => [[
                'delta' => [
                    'tool_calls' => [[
                        'index' => 0,
                        'function' => [
                            'name' => '',
                            'arguments' => $part2,
                        ],
                    ]],
                ],
            ]],
        ]);

        return "data: {$chunk1}\n\ndata: {$chunk2}\n\ndata: [DONE]\n\n";
    }

    // -------------------------------------------------------------------------
    // Tool definitions included in LLM request
    // -------------------------------------------------------------------------

    public function test_tool_definitions_are_sent_to_llm_when_tools_registered(): void
    {
        $this->configureAi();

        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();

        Http::fake(['*/chat/completions' => Http::response($this->fakeContentSse('ok'))]);

        // streamedContent() forces the StreamedResponse callback to execute,
        // which triggers the HTTP call to the LLM — necessary for Http::assertSent.
        $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hello'])
            ->assertOk()
            ->streamedContent();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['tools']) && is_array($body['tools']) && count($body['tools']) > 0;
        });
    }

    public function test_tool_definitions_contain_create_ticket(): void
    {
        $this->configureAi();

        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();

        Http::fake(['*/chat/completions' => Http::response($this->fakeContentSse('ok'))]);

        $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hello'])
            ->assertOk()
            ->streamedContent();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $tools = $body['tools'] ?? [];

            foreach ($tools as $tool) {
                if (($tool['function']['name'] ?? '') === 'create_ticket') {
                    return true;
                }
            }

            return false;
        });
    }

    // -------------------------------------------------------------------------
    // Tool call SSE events
    // -------------------------------------------------------------------------

    public function test_tool_call_delta_emits_tool_call_sse_event(): void
    {
        $this->configureAi();

        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();
        Category::factory()->create();

        $arguments = [
            'title' => 'Retry queue',
            'description' => 'Implement retry queue.',
            'priority' => 'high',
        ];

        Http::fake(['*/chat/completions' => Http::response(
            $this->fakeToolCallSse('create_ticket', $arguments)
        )]);

        $content = $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Create a ticket'])
            ->streamedContent();

        // Should contain a tool_call event
        $this->assertStringContainsString('"type":"tool_call"', $content);
        $this->assertStringContainsString('"tool":"create_ticket"', $content);
        $this->assertStringContainsString('"requires_confirmation"', $content);
    }

    public function test_tool_call_event_does_not_wrap_in_token_key(): void
    {
        $this->configureAi();

        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();
        Category::factory()->create();

        $arguments = ['title' => 'Test', 'description' => 'Desc'];

        Http::fake(['*/chat/completions' => Http::response(
            $this->fakeToolCallSse('create_ticket', $arguments)
        )]);

        $content = $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Create a ticket'])
            ->streamedContent();

        // Parse all SSE events and check none of the tool_call events use the token wrapper
        foreach (explode("\n\n", $content) as $event) {
            $event = trim($event);

            if (! str_starts_with($event, 'data: ')) {
                continue;
            }

            $data = substr($event, 6);

            if ($data === '[DONE]') {
                continue;
            }

            $parsed = json_decode($data, true);

            if (! is_array($parsed)) {
                continue;
            }

            if (($parsed['type'] ?? '') === 'tool_call') {
                // Must not be wrapped in {"token": ...}
                $this->assertArrayNotHasKey('token', $parsed, 'tool_call events should not have a token key');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Regular content still works when tools registered
    // -------------------------------------------------------------------------

    public function test_regular_content_tokens_still_stream_when_tools_registered(): void
    {
        $this->configureAi();

        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();

        Http::fake(['*/chat/completions' => Http::response($this->fakeContentSse('Hello', ' there'))]);

        $content = $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Hi'])
            ->streamedContent();

        $this->assertStringContainsString('"token":"Hello"', $content);
        $this->assertStringContainsString('"token":" there"', $content);
    }

    // -------------------------------------------------------------------------
    // Fallback text-parse for non-function-calling models (Ollama driver)
    // -------------------------------------------------------------------------

    public function test_fallback_tool_call_detected_in_text_response(): void
    {
        // Configure as ollama driver (non-function-calling)
        AiSetting::current()->update([
            'provider' => 'ollama',
            'base_url' => 'http://fake-llm.test/v1',
            'api_key' => 'test-key',
            'model' => 'test-model',
        ]);

        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();
        Category::factory()->create();

        $toolCallJson = '{"tool_call": {"name": "create_ticket", "arguments": {"title": "Test", "description": "Desc"}}}';
        $responseText = "I will create a ticket for you.\n{$toolCallJson}";

        // Build fake SSE with embedded tool_call JSON in content
        $sseBody = 'data: '.json_encode([
            'choices' => [['delta' => ['content' => $responseText]]],
        ])."\n\ndata: [DONE]\n\n";

        Http::fake(['*/chat/completions' => Http::response($sseBody)]);

        $content = $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat", ['message' => 'Create a ticket'])
            ->streamedContent();

        $this->assertStringContainsString('"type":"tool_call"', $content);
        $this->assertStringContainsString('"tool":"create_ticket"', $content);
    }
}
