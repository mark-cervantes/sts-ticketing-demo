<?php

namespace Tests\Feature\Api;

use App\Jobs\GenerateSummaryJob;
use App\Models\Category;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for POST /api/issues/{issue}/chat/tool-confirm.
 *
 * @see task 09.04 / IssueChatController::confirmTool()
 */
class ToolConfirmEndpointTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function validPayload(int $categoryId): array
    {
        return [
            'tool' => 'create_ticket',
            'arguments' => [
                'title' => 'Retry queue',
                'description' => 'Implement a local retry queue to buffer peak-hour gateway timeouts.',
                'priority' => 'high',
                'category_id' => $categoryId,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function test_unauthenticated_user_cannot_confirm_tool(): void
    {
        $issue = Issue::factory()->public()->create();

        $this->postJson("/api/issues/{$issue->id}/chat/tool-confirm", [
            'tool' => 'create_ticket',
            'arguments' => ['title' => 'Test', 'description' => 'Test'],
        ])->assertUnauthorized();
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    public function test_unauthorized_user_gets_403_on_private_issue(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        $category = Category::factory()->create();

        $this->actingAs($other)
            ->postJson("/api/issues/{$issue->id}/chat/tool-confirm", $this->validPayload($category->id))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_tool_field_is_required(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();

        $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat/tool-confirm", [
                'arguments' => ['title' => 'Test', 'description' => 'Desc'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tool']);
    }

    public function test_arguments_field_is_required(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();

        $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat/tool-confirm", [
                'tool' => 'create_ticket',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['arguments']);
    }

    public function test_arguments_must_be_array(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();

        $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat/tool-confirm", [
                'tool' => 'create_ticket',
                'arguments' => 'not-an-array',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['arguments']);
    }

    // -------------------------------------------------------------------------
    // Unknown tool
    // -------------------------------------------------------------------------

    public function test_unknown_tool_returns_422(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();

        $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat/tool-confirm", [
                'tool' => 'nonexistent_tool',
                'arguments' => ['anything' => 'value'],
            ])
            ->assertUnprocessable()
            ->assertJson(['message' => 'Unknown tool: nonexistent_tool']);
    }

    // -------------------------------------------------------------------------
    // Happy path — issue creation
    // -------------------------------------------------------------------------

    public function test_confirm_tool_creates_issue_and_returns_success(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();
        $category = Category::factory()->create(['name' => 'Backend']);

        $response = $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat/tool-confirm", [
                'tool' => 'create_ticket',
                'arguments' => [
                    'title' => 'Retry queue',
                    'description' => 'Implement retry queue.',
                    'priority' => 'high',
                    'category' => 'Backend',
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('tool_name', 'create_ticket')
            ->assertJsonPath('pending_confirmation', false);

        $this->assertDatabaseHas('issues', [
            'title' => 'Retry queue',
            'user_id' => $user->id,
        ]);
    }

    public function test_confirm_tool_dispatches_generate_summary_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();
        Category::factory()->create();

        $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat/tool-confirm", [
                'tool' => 'create_ticket',
                'arguments' => [
                    'title' => 'A new ticket',
                    'description' => 'Full description here.',
                ],
            ]);

        Queue::assertPushed(GenerateSummaryJob::class);
    }

    public function test_response_contains_new_issue_id_title_and_url(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->public()->create();
        Category::factory()->create();

        $response = $this->actingAs($user)
            ->postJson("/api/issues/{$issue->id}/chat/tool-confirm", [
                'tool' => 'create_ticket',
                'arguments' => [
                    'title' => 'Link test ticket',
                    'description' => 'Check the returned URL.',
                ],
            ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['id', 'title', 'url']]);

        $data = $response->json('data');
        $this->assertNotNull($data['id']);
        $this->assertSame('Link test ticket', $data['title']);
        $this->assertStringContainsString((string) $data['id'], $data['url']);
    }

    // -------------------------------------------------------------------------
    // Shared issue — viewer can also create follow-up tickets
    // -------------------------------------------------------------------------

    public function test_shared_viewer_can_confirm_create_ticket(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        Category::factory()->create();

        // Share with view permission
        $issue->shares()->create([
            'user_id' => $viewer->id,
            'permission' => 'view',
        ]);

        $this->actingAs($viewer)
            ->postJson("/api/issues/{$issue->id}/chat/tool-confirm", [
                'tool' => 'create_ticket',
                'arguments' => [
                    'title' => 'Follow-up',
                    'description' => 'A follow-up ticket from a shared viewer.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
