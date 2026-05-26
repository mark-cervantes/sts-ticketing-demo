<?php

namespace App\Services\Ai\Tools;

use App\Models\Issue;
use App\Models\User;

/**
 * Contract every AI chat tool must implement.
 *
 * Each tool is self-describing (name, description, parameterSchema) so that
 * the ChatToolRegistry can export OpenAI function-calling definitions without
 * knowing about individual tools. Tools declare their own discovery chip text
 * via suggestionChip() and their confirmation requirement via requiresConfirmation().
 *
 * @see task 09.04 / ADR-002
 */
interface ChatToolInterface
{
    /** Machine-readable tool name used in function-calling API and routing. */
    public function name(): string;

    /** Human-readable description sent to the LLM in the tool list. */
    public function description(): string;

    /**
     * OpenAI JSON Schema for the tool's parameters.
     *
     * @return array<string, mixed>
     */
    public function parameterSchema(): array;

    /**
     * Execute the tool with the resolved parameters.
     *
     * When requiresConfirmation() is true, execute() called from the streaming
     * path should return a result with pendingConfirmation=true WITHOUT actually
     * performing the action. Real execution happens at the tool-confirm endpoint.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function execute(array $parameters, Issue $issue, User $user): ChatToolResult;

    /**
     * Whether this tool requires explicit user confirmation before execution.
     *
     * When true, the streaming path yields a tool_call event and stops.
     * Execution only happens when the user clicks "Create" in the UI and
     * the tool-confirm endpoint is called.
     */
    public function requiresConfirmation(): bool;

    /**
     * Suggestion chip text shown in the empty-state chat panel.
     *
     * Return null if this tool should not appear as a suggestion chip.
     */
    public function suggestionChip(): ?string;
}
