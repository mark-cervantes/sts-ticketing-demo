<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Issue;
use App\Models\IssueConversation;
use App\Models\IssueConversationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Tests for saved conversation endpoints.
 *
 * GET/POST /api/issues/{issue}/conversations
 * GET /api/issues/{issue}/conversations/{conversation}
 * POST /api/issues/{issue}/conversations/{conversation}/continue
 *
 * @see task 09.01 / IssueConversationController
 */
class IssueConversationTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fakeSseBody(string ...$tokens): string
    {
        $lines = '';
        foreach ($tokens as $token) {
            $lines .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $token]]]])."\n\n";
        }
        $lines .= "data: [DONE]\n\n";

        return $lines;
    }

    private function configureAi(): void
    {
        AiSetting::current()->update([
            'provider' => 'custom',
            'base_url' => 'http://fake-llm.test/v1',
            'api_key' => 'test-key',
            'model' => 'test-model',
        ]);
    }

    private function sampleMessages(): array
    {
        return [
            ['role' => 'user', 'content' => 'What is the root cause?'],
            ['role' => 'assistant', 'content' => 'The timeout is caused by a slow database query.'],
        ];
    }

    // -------------------------------------------------------------------------
    // Save Conversation — POST /api/issues/{issue}/conversations
    // -------------------------------------------------------------------------

    public function test_owner_can_save_conversation(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations", [
                'title' => 'Root cause discussion',
                'messages' => $this->sampleMessages(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Root cause discussion')
            ->assertJsonPath('data.messages_count', 2)
            ->assertJsonPath('data.saved_by.id', $owner->id);
    }

    public function test_any_viewer_can_save_conversation(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->actingAs($viewer)
            ->postJson("/api/issues/{$issue->id}/conversations", [
                'messages' => $this->sampleMessages(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.saved_by.id', $viewer->id);
    }

    public function test_unauthorized_user_cannot_save_conversation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $this->actingAs($other)
            ->postJson("/api/issues/{$issue->id}/conversations", [
                'messages' => $this->sampleMessages(),
            ])
            ->assertForbidden();
    }

    public function test_saving_conversation_persists_messages(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations", [
                'messages' => $this->sampleMessages(),
            ])
            ->assertCreated();

        $this->assertDatabaseCount('issue_conversations', 1);
        $this->assertDatabaseCount('issue_conversation_messages', 2);

        $this->assertDatabaseHas('issue_conversation_messages', [
            'role' => 'user',
            'content' => 'What is the root cause?',
        ]);
        $this->assertDatabaseHas('issue_conversation_messages', [
            'role' => 'assistant',
            'content' => 'The timeout is caused by a slow database query.',
            'user_id' => null,
        ]);
    }

    public function test_save_requires_at_least_two_messages(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations", [
                'messages' => [['role' => 'user', 'content' => 'Just one']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['messages']);
    }

    public function test_save_title_is_optional(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations", [
                'messages' => $this->sampleMessages(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', null);
    }

    public function test_unauthenticated_user_cannot_save_conversation(): void
    {
        $issue = Issue::factory()->public()->create();

        $this->postJson("/api/issues/{$issue->id}/conversations", [
            'messages' => $this->sampleMessages(),
        ])
            ->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // List Conversations — GET /api/issues/{issue}/conversations
    // -------------------------------------------------------------------------

    public function test_can_list_conversations_for_issue(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $conv1 = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
            'title' => 'First conversation',
        ]);
        IssueConversationMessage::factory()->create([
            'conversation_id' => $conv1->id,
            'role' => 'user',
            'content' => 'question',
            'user_id' => $owner->id,
        ]);

        IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
            'title' => 'Second conversation',
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/issues/{$issue->id}/conversations")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('title', $data[0]);
        $this->assertArrayHasKey('messages_count', $data[0]);
        $this->assertArrayHasKey('saved_by', $data[0]);
    }

    public function test_list_returns_only_conversations_for_requested_issue(): void
    {
        $owner = User::factory()->create();
        $issue1 = Issue::factory()->for($owner)->public()->create();
        $issue2 = Issue::factory()->for($owner)->public()->create();

        IssueConversation::factory()->create(['issue_id' => $issue1->id, 'saved_by' => $owner->id]);
        IssueConversation::factory()->create(['issue_id' => $issue2->id, 'saved_by' => $owner->id]);

        $response = $this->actingAs($owner)
            ->getJson("/api/issues/{$issue1->id}/conversations")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_unauthorized_user_cannot_list_conversations(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $this->actingAs($other)
            ->getJson("/api/issues/{$issue->id}/conversations")
            ->assertForbidden();
    }

    public function test_list_is_ordered_newest_first(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $older = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
            'title' => 'Older',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $newer = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
            'title' => 'Newer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/issues/{$issue->id}/conversations")
            ->assertOk();

        $this->assertEquals('Newer', $response->json('data.0.title'));
        $this->assertEquals('Older', $response->json('data.1.title'));
    }

    public function test_list_includes_messages_count(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        IssueConversationMessage::factory()->count(3)->create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'user_id' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/issues/{$issue->id}/conversations")
            ->assertOk();

        $this->assertEquals(3, $response->json('data.0.messages_count'));
    }

    // -------------------------------------------------------------------------
    // Show Conversation — GET /api/issues/{issue}/conversations/{conversation}
    // -------------------------------------------------------------------------

    public function test_can_show_conversation_with_messages(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
            'title' => 'My conversation',
        ]);

        IssueConversationMessage::factory()->create([
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => 'Hello AI',
            'user_id' => $owner->id,
        ]);
        IssueConversationMessage::factory()->create([
            'conversation_id' => $conv->id,
            'role' => 'assistant',
            'content' => 'Hello human',
            'user_id' => null,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/issues/{$issue->id}/conversations/{$conv->id}")
            ->assertOk();

        $this->assertEquals('My conversation', $response->json('data.title'));
        $this->assertCount(2, $response->json('data.messages'));
        $this->assertEquals('user', $response->json('data.messages.0.role'));
        $this->assertEquals('assistant', $response->json('data.messages.1.role'));
    }

    public function test_unauthorized_user_cannot_show_conversation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        $this->actingAs($other)
            ->getJson("/api/issues/{$issue->id}/conversations/{$conv->id}")
            ->assertForbidden();
    }

    public function test_any_viewer_can_show_conversation(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        $this->actingAs($viewer)
            ->getJson("/api/issues/{$issue->id}/conversations/{$conv->id}")
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Continue Conversation — POST /api/issues/{issue}/conversations/{conversation}/continue
    // -------------------------------------------------------------------------

    public function test_can_continue_conversation_and_get_stream(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('AI', ' says', ' hi'))]);

        $response = $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations/{$conv->id}/continue", [
                'message' => 'Any updates?',
            ]);

        $response->assertOk()
            ->assertHeaderContains('Content-Type', 'text/event-stream');
    }

    public function test_continue_persists_user_message_before_streaming(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('response'))]);

        // streamedContent() triggers full closure execution
        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations/{$conv->id}/continue", [
                'message' => 'New question',
            ])
            ->streamedContent();

        $this->assertDatabaseHas('issue_conversation_messages', [
            'conversation_id' => $conv->id,
            'role' => 'user',
            'content' => 'New question',
            'user_id' => $owner->id,
        ]);
    }

    public function test_continue_persists_ai_response_after_stream(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('AI', ' answer', ' here'))]);

        // Must call streamedContent() to trigger full closure execution (including AI message save)
        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations/{$conv->id}/continue", [
                'message' => 'Question',
            ])
            ->streamedContent();

        // Both user and AI messages should be persisted
        $this->assertDatabaseHas('issue_conversation_messages', [
            'conversation_id' => $conv->id,
            'role' => 'assistant',
            'user_id' => null,
        ]);
    }

    public function test_continue_stream_includes_done_sentinel(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('token'))]);

        $content = $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations/{$conv->id}/continue", [
                'message' => 'Hi',
            ])
            ->streamedContent();

        $this->assertStringContainsString('[DONE]', $content);
    }

    public function test_continue_returns_503_when_ai_not_configured(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        AiSetting::current()->update(['provider' => 'rules']);

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations/{$conv->id}/continue", [
                'message' => 'Any updates?',
            ])
            ->assertStatus(503);
    }

    public function test_continue_rate_limit_returns_429(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('token'))]);

        // Exhaust the rate limit
        RateLimiter::clear('chat:'.$owner->id.':'.$issue->id);
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('chat:'.$owner->id.':'.$issue->id, 3600);
        }

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations/{$conv->id}/continue", [
                'message' => 'Hi',
            ])
            ->assertStatus(429);
    }

    public function test_unauthorized_user_cannot_continue_conversation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        $this->configureAi();

        $this->actingAs($other)
            ->postJson("/api/issues/{$issue->id}/conversations/{$conv->id}/continue", [
                'message' => 'Hi',
            ])
            ->assertForbidden();
    }

    public function test_any_viewer_can_continue_saved_conversation(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        $this->configureAi();
        Http::fake(['*/chat/completions' => Http::response($this->fakeSseBody('response'))]);

        $this->actingAs($viewer)
            ->postJson("/api/issues/{$issue->id}/conversations/{$conv->id}/continue", [
                'message' => 'Follow up question',
            ])
            ->assertOk();
    }

    public function test_continue_message_is_required(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();
        $conv = IssueConversation::factory()->create([
            'issue_id' => $issue->id,
            'saved_by' => $owner->id,
        ]);

        $this->configureAi();

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/conversations/{$conv->id}/continue", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message']);
    }
}
