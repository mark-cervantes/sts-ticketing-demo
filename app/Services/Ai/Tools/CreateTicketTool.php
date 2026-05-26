<?php

namespace App\Services\Ai\Tools;

use App\Enums\Priority;
use App\Models\Category;
use App\Models\Issue;
use App\Models\User;
use App\Services\IssueService;

/**
 * AI chat tool — creates a new support ticket on behalf of the user.
 *
 * Requires user confirmation before executing (requiresConfirmation = true).
 *
 * When called from the streaming path the LLM has indicated it wants to create
 * a ticket. execute() returns a pendingConfirmation result with the pre-filled
 * arguments so the frontend can render a confirmation card.
 *
 * When called from the tool-confirm endpoint (after the user clicked "Create"),
 * the $confirmed flag is true and the issue is actually created via IssueService.
 *
 * Category resolution: the LLM supplies a human-readable category name string.
 * This tool resolves it to a category_id via a case-insensitive LIKE query.
 * Falls back to the first category when no match is found.
 *
 * @see task 09.04 / ChatToolRegistry / IssueChatController::confirmTool()
 */
class CreateTicketTool implements ChatToolInterface
{
    public function name(): string
    {
        return 'create_ticket';
    }

    public function description(): string
    {
        return 'Create a new support ticket. Use when the user asks to create a ticket, report a related issue, or log a follow-up.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Short, descriptive title for the ticket (max 255 characters).',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Full description of the issue to be tracked.',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'medium', 'high', 'critical'],
                    'description' => 'Ticket priority level.',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Category name for the ticket. Will be matched against existing categories.',
                ],
            ],
            'required' => ['title', 'description'],
        ];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function suggestionChip(): ?string
    {
        return 'Create a follow-up ticket';
    }

    /**
     * Execute the tool.
     *
     * When $confirmed is false (default — streaming path), returns a
     * pendingConfirmation result with validated data so the frontend can render
     * the confirmation card. No issue is created.
     *
     * When $confirmed is true (tool-confirm endpoint path), creates the issue
     * via IssueService and returns the created issue's id, title, and URL.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function execute(array $parameters, Issue $issue, User $user, bool $confirmed = false): ChatToolResult
    {
        // Basic validation
        $title = trim((string) ($parameters['title'] ?? ''));
        $description = trim((string) ($parameters['description'] ?? ''));
        $priorityValue = $parameters['priority'] ?? 'medium';
        $categoryName = isset($parameters['category']) ? trim((string) $parameters['category']) : null;

        if ($title === '') {
            return new ChatToolResult(
                toolName: $this->name(),
                success: false,
                message: 'Title is required to create a ticket.',
            );
        }

        if (strlen($title) > 255) {
            return new ChatToolResult(
                toolName: $this->name(),
                success: false,
                message: 'Title must not exceed 255 characters.',
            );
        }

        if ($description === '') {
            return new ChatToolResult(
                toolName: $this->name(),
                success: false,
                message: 'Description is required to create a ticket.',
            );
        }

        // Validate priority
        $priority = Priority::tryFrom($priorityValue);

        if ($priority === null) {
            $priority = Priority::Medium;
        }

        // Resolve category name → ID
        $category = null;

        if ($categoryName !== null && $categoryName !== '') {
            $category = Category::where('name', 'like', $categoryName)->first();
        }

        if ($category === null) {
            $category = Category::first();
        }

        if ($category === null) {
            return new ChatToolResult(
                toolName: $this->name(),
                success: false,
                message: 'No categories exist. Please create a category first.',
            );
        }

        // Streaming path: return pre-flight data for confirmation card
        if (! $confirmed) {
            return new ChatToolResult(
                toolName: $this->name(),
                success: true,
                message: 'Ready to create ticket — awaiting confirmation.',
                data: [
                    'title' => $title,
                    'description' => $description,
                    'priority' => $priority->value,
                    'category_id' => $category->id,
                    'category_name' => $category->name,
                ],
                pendingConfirmation: true,
            );
        }

        // Confirmed path: create the issue via IssueService
        $issueService = app(IssueService::class);

        $newIssue = $issueService->create($user, [
            'title' => $title,
            'description' => $description,
            'priority' => $priority->value,
            'category_id' => $category->id,
        ]);

        return new ChatToolResult(
            toolName: $this->name(),
            success: true,
            message: "Ticket #{$newIssue->id} created successfully.",
            data: [
                'id' => $newIssue->id,
                'title' => $newIssue->title,
                'url' => route('issues.show', $newIssue->id),
            ],
        );
    }
}
