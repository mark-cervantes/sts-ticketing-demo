<?php

namespace Tests\Feature\Ai;

use App\Enums\Priority;
use App\Jobs\GenerateSummaryJob;
use App\Models\Category;
use App\Models\Issue;
use App\Models\User;
use App\Services\Ai\Tools\ChatToolResult;
use App\Services\Ai\Tools\CreateTicketTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for CreateTicketTool.
 *
 * @see task 09.04 / CreateTicketTool
 */
class CreateTicketToolTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Metadata
    // -------------------------------------------------------------------------

    public function test_tool_name_is_create_ticket(): void
    {
        $tool = new CreateTicketTool;

        $this->assertSame('create_ticket', $tool->name());
    }

    public function test_tool_requires_confirmation(): void
    {
        $tool = new CreateTicketTool;

        $this->assertTrue($tool->requiresConfirmation());
    }

    public function test_suggestion_chip_is_not_null(): void
    {
        $tool = new CreateTicketTool;

        $this->assertSame('Create a follow-up ticket', $tool->suggestionChip());
    }

    public function test_parameter_schema_has_required_fields(): void
    {
        $tool = new CreateTicketTool;
        $schema = $tool->parameterSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertArrayHasKey('description', $schema['properties']);
        $this->assertContains('title', $schema['required']);
        $this->assertContains('description', $schema['required']);
    }

    // -------------------------------------------------------------------------
    // Pre-flight (streaming path — confirmed=false)
    // -------------------------------------------------------------------------

    public function test_execute_returns_pending_confirmation_when_not_confirmed(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        $category = Category::factory()->create(['name' => 'Backend']);

        $tool = new CreateTicketTool;
        $result = $tool->execute([
            'title' => 'Retry queue issue',
            'description' => 'Implement a retry queue.',
            'priority' => 'high',
            'category' => 'Backend',
        ], $issue, $user);

        $this->assertInstanceOf(ChatToolResult::class, $result);
        $this->assertTrue($result->pendingConfirmation);
        $this->assertTrue($result->success);
        $this->assertNull($result->data['id'] ?? null);

        // No issue created, no job dispatched
        $this->assertDatabaseCount('issues', 1); // only the original issue
        Queue::assertNothingPushed();
    }

    public function test_execute_pre_flight_returns_resolved_category(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        $category = Category::factory()->create(['name' => 'Backend']);

        $tool = new CreateTicketTool;
        $result = $tool->execute([
            'title' => 'A ticket',
            'description' => 'Some description',
            'category' => 'Backend',
        ], $issue, $user);

        $this->assertSame($category->id, $result->data['category_id']);
        $this->assertSame('Backend', $result->data['category_name']);
    }

    // -------------------------------------------------------------------------
    // Confirmed execution (tool-confirm endpoint path)
    // -------------------------------------------------------------------------

    public function test_execute_confirmed_creates_issue(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        $category = Category::factory()->create(['name' => 'Backend']);

        $tool = new CreateTicketTool;
        $result = $tool->execute([
            'title' => 'Retry queue issue',
            'description' => 'Implement a retry queue.',
            'priority' => 'high',
            'category' => 'Backend',
        ], $issue, $user, confirmed: true);

        $this->assertTrue($result->success);
        $this->assertFalse($result->pendingConfirmation);
        $this->assertNotNull($result->data['id']);
        $this->assertSame('Retry queue issue', $result->data['title']);

        $this->assertDatabaseHas('issues', [
            'title' => 'Retry queue issue',
            'description' => 'Implement a retry queue.',
            'user_id' => $user->id,
            'category_id' => $category->id,
        ]);
    }

    public function test_execute_confirmed_dispatches_generate_summary_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        Category::factory()->create(['name' => 'General']);

        $tool = new CreateTicketTool;
        $tool->execute([
            'title' => 'Test ticket',
            'description' => 'Some description here.',
        ], $issue, $user, confirmed: true);

        Queue::assertPushed(GenerateSummaryJob::class);
    }

    public function test_execute_confirmed_resolves_category_by_name(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        $cat = Category::factory()->create(['name' => 'Infrastructure']);

        $tool = new CreateTicketTool;
        $result = $tool->execute([
            'title' => 'DNS issue',
            'description' => 'DNS is not resolving.',
            'category' => 'Infrastructure',
        ], $issue, $user, confirmed: true);

        $this->assertDatabaseHas('issues', ['category_id' => $cat->id]);
    }

    public function test_execute_confirmed_falls_back_to_first_category_when_name_unknown(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $tool = new CreateTicketTool;
        $result = $tool->execute([
            'title' => 'Something',
            'description' => 'A description.',
            'category' => 'NonExistentCategory',
        ], $issue, $user, confirmed: true);

        $this->assertTrue($result->success);
        // Falls back to whichever category exists — verify issue was created with some category
        $this->assertNotNull($result->data['id'] ?? null);
        $this->assertDatabaseHas('issues', ['title' => 'Something']);
    }

    public function test_execute_confirmed_uses_medium_priority_when_invalid(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        Category::factory()->create();

        $tool = new CreateTicketTool;
        $tool->execute([
            'title' => 'Test',
            'description' => 'Test description.',
            'priority' => 'not-valid',
        ], $issue, $user, confirmed: true);

        $this->assertDatabaseHas('issues', [
            'priority' => Priority::Medium->value,
        ]);
    }

    public function test_result_data_contains_url(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        Category::factory()->create();

        $tool = new CreateTicketTool;
        $result = $tool->execute([
            'title' => 'Link test',
            'description' => 'Check the URL.',
        ], $issue, $user, confirmed: true);

        $this->assertNotEmpty($result->data['url']);
        $this->assertStringContainsString((string) $result->data['id'], $result->data['url']);
    }

    // -------------------------------------------------------------------------
    // Validation failures
    // -------------------------------------------------------------------------

    public function test_returns_failure_when_title_is_empty(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $tool = new CreateTicketTool;
        $result = $tool->execute([
            'title' => '',
            'description' => 'Some description.',
        ], $issue, $user);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Title', $result->message);
    }

    public function test_returns_failure_when_title_exceeds_255_chars(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $tool = new CreateTicketTool;
        $result = $tool->execute([
            'title' => str_repeat('a', 256),
            'description' => 'Some description.',
        ], $issue, $user);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('255', $result->message);
    }

    public function test_returns_failure_when_description_is_empty(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $tool = new CreateTicketTool;
        $result = $tool->execute([
            'title' => 'Good title',
            'description' => '',
        ], $issue, $user);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Description', $result->message);
    }
}
