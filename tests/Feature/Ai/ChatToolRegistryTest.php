<?php

namespace Tests\Feature\Ai;

use App\Models\Issue;
use App\Models\User;
use App\Services\Ai\Tools\ChatToolInterface;
use App\Services\Ai\Tools\ChatToolRegistry;
use App\Services\Ai\Tools\ChatToolResult;
use App\Services\Ai\Tools\CreateTicketTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests for ChatToolRegistry.
 *
 * @see task 09.04 / ChatToolRegistry
 */
class ChatToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    public function test_registry_is_empty_by_default(): void
    {
        $registry = new ChatToolRegistry;

        $this->assertTrue($registry->isEmpty());
    }

    public function test_registry_is_not_empty_after_registration(): void
    {
        $registry = new ChatToolRegistry;
        $registry->register(new CreateTicketTool);

        $this->assertFalse($registry->isEmpty());
    }

    public function test_has_tool_returns_true_for_registered_tool(): void
    {
        $registry = new ChatToolRegistry;
        $registry->register(new CreateTicketTool);

        $this->assertTrue($registry->hasTool('create_ticket'));
    }

    public function test_has_tool_returns_false_for_unregistered_tool(): void
    {
        $registry = new ChatToolRegistry;

        $this->assertFalse($registry->hasTool('nonexistent'));
    }

    public function test_later_registration_overwrites_earlier_one(): void
    {
        $registry = new ChatToolRegistry;

        $toolA = $this->mockTool('my_tool', 'First version');
        $toolB = $this->mockTool('my_tool', 'Second version');

        $registry->register($toolA);
        $registry->register($toolB);

        $defs = $registry->getToolDefinitions();
        $this->assertCount(1, $defs);
        $this->assertSame('Second version', $defs[0]['function']['description']);
    }

    // -------------------------------------------------------------------------
    // Tool Definitions
    // -------------------------------------------------------------------------

    public function test_get_tool_definitions_returns_openai_format(): void
    {
        $registry = new ChatToolRegistry;
        $registry->register(new CreateTicketTool);

        $defs = $registry->getToolDefinitions();

        $this->assertCount(1, $defs);
        $this->assertSame('function', $defs[0]['type']);
        $this->assertSame('create_ticket', $defs[0]['function']['name']);
        $this->assertNotEmpty($defs[0]['function']['description']);
        $this->assertIsArray($defs[0]['function']['parameters']);
    }

    public function test_get_tool_definitions_returns_empty_when_no_tools(): void
    {
        $registry = new ChatToolRegistry;

        $this->assertSame([], $registry->getToolDefinitions());
    }

    // -------------------------------------------------------------------------
    // Suggestion Chips
    // -------------------------------------------------------------------------

    public function test_get_suggestion_chips_returns_chips_from_tools(): void
    {
        $registry = new ChatToolRegistry;
        $registry->register(new CreateTicketTool);

        $chips = $registry->getSuggestionChips();

        $this->assertContains('Create a follow-up ticket', $chips);
    }

    public function test_get_suggestion_chips_omits_null_chips(): void
    {
        $registry = new ChatToolRegistry;
        $noChipTool = $this->mockTool('no_chip', 'A tool with no chip', null);
        $registry->register($noChipTool);

        $chips = $registry->getSuggestionChips();

        $this->assertEmpty($chips);
    }

    public function test_get_suggestion_chips_returns_empty_for_empty_registry(): void
    {
        $registry = new ChatToolRegistry;

        $this->assertSame([], $registry->getSuggestionChips());
    }

    // -------------------------------------------------------------------------
    // Requires Confirmation
    // -------------------------------------------------------------------------

    public function test_requires_confirmation_returns_true_for_create_ticket(): void
    {
        $registry = new ChatToolRegistry;
        $registry->register(new CreateTicketTool);

        $this->assertTrue($registry->requiresConfirmation('create_ticket'));
    }

    public function test_requires_confirmation_returns_false_for_unregistered_tool(): void
    {
        $registry = new ChatToolRegistry;

        $this->assertFalse($registry->requiresConfirmation('nonexistent'));
    }

    // -------------------------------------------------------------------------
    // Execute Tool
    // -------------------------------------------------------------------------

    public function test_execute_tool_throws_for_unregistered_tool(): void
    {
        $registry = new ChatToolRegistry;
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->expectException(InvalidArgumentException::class);

        $registry->executeTool('nonexistent', [], $issue, $user);
    }

    public function test_execute_tool_returns_chat_tool_result(): void
    {
        $registry = new ChatToolRegistry;
        $registry->register(new CreateTicketTool);

        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $result = $registry->executeTool('create_ticket', [
            'title' => 'Test ticket',
            'description' => 'Test description',
        ], $issue, $user);

        $this->assertInstanceOf(ChatToolResult::class, $result);
        // CreateTicketTool requires confirmation so pendingConfirmation = true
        $this->assertTrue($result->pendingConfirmation);
        $this->assertSame('create_ticket', $result->toolName);
    }

    // -------------------------------------------------------------------------
    // Singleton in container
    // -------------------------------------------------------------------------

    public function test_registry_is_resolved_as_singleton(): void
    {
        $a = $this->app->make(ChatToolRegistry::class);
        $b = $this->app->make(ChatToolRegistry::class);

        $this->assertSame($a, $b);
    }

    public function test_singleton_registry_has_create_ticket_tool_registered(): void
    {
        $registry = $this->app->make(ChatToolRegistry::class);

        $this->assertTrue($registry->hasTool('create_ticket'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function mockTool(string $name, string $description, ?string $chip = 'chip'): ChatToolInterface
    {
        return new class($name, $description, $chip) implements ChatToolInterface
        {
            public function __construct(
                private string $toolName,
                private string $toolDescription,
                private ?string $chip,
            ) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return $this->toolDescription;
            }

            public function parameterSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $parameters, Issue $issue, User $user): ChatToolResult
            {
                return new ChatToolResult($this->toolName, true, 'ok');
            }

            public function requiresConfirmation(): bool
            {
                return false;
            }

            public function suggestionChip(): ?string
            {
                return $this->chip;
            }
        };
    }
}
