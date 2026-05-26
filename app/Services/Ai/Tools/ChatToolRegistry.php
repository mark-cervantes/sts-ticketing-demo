<?php

namespace App\Services\Ai\Tools;

use App\Models\Issue;
use App\Models\User;

/**
 * Registry of all registered AI chat tools.
 *
 * Registered explicitly in AppServiceProvider::register() (not auto-discovered)
 * to ensure predictable ordering and avoid hidden magic.
 *
 * Provides:
 *  - OpenAI function-calling definitions for the LLM request body
 *  - Suggestion chip texts for the empty-state chat panel
 *  - Unified tool execution routing
 *
 * @see task 09.04 / AppServiceProvider / ChatService
 */
class ChatToolRegistry
{
    /** @var array<string, ChatToolInterface> */
    private array $tools = [];

    /** Register a tool. Later registrations with the same name overwrite earlier ones. */
    public function register(ChatToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** Whether no tools are registered. */
    public function isEmpty(): bool
    {
        return $this->tools === [];
    }

    /** Whether a tool with the given name is registered. */
    public function hasTool(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * OpenAI function-calling tool definitions array.
     *
     * Format: [{ type: "function", function: { name, description, parameters } }]
     *
     * @return array<int, array<string, mixed>>
     */
    public function getToolDefinitions(): array
    {
        return array_values(array_map(
            fn (ChatToolInterface $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $tool->parameterSchema(),
                ],
            ],
            $this->tools,
        ));
    }

    /**
     * Collect non-null suggestion chip texts from all registered tools.
     *
     * @return array<int, string>
     */
    public function getSuggestionChips(): array
    {
        $chips = [];

        foreach ($this->tools as $tool) {
            $chip = $tool->suggestionChip();

            if ($chip !== null) {
                $chips[] = $chip;
            }
        }

        return $chips;
    }

    /**
     * Whether the named tool requires user confirmation before executing.
     *
     * Returns false when the tool is not registered.
     */
    public function requiresConfirmation(string $name): bool
    {
        if (! $this->hasTool($name)) {
            return false;
        }

        return $this->tools[$name]->requiresConfirmation();
    }

    /**
     * Execute a named tool (streaming / pre-flight path).
     *
     * For confirmation-required tools this returns a pendingConfirmation result
     * without actually performing the action.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws \InvalidArgumentException when the tool is not registered
     */
    public function executeTool(string $name, array $params, Issue $issue, User $user): ChatToolResult
    {
        if (! $this->hasTool($name)) {
            throw new \InvalidArgumentException("Tool '{$name}' is not registered.");
        }

        return $this->tools[$name]->execute($params, $issue, $user);
    }

    /**
     * Execute a named tool on the confirmed path (after user clicked "Create").
     *
     * Calls execute() with confirmed=true so that CreateTicketTool actually creates
     * the issue rather than returning a pre-flight result.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws \InvalidArgumentException when the tool is not registered
     */
    public function executeToolConfirmed(string $name, array $params, Issue $issue, User $user): ChatToolResult
    {
        if (! $this->hasTool($name)) {
            throw new \InvalidArgumentException("Tool '{$name}' is not registered.");
        }

        $tool = $this->tools[$name];

        // If the tool supports a $confirmed parameter (like CreateTicketTool), invoke it.
        // Use reflection to detect the parameter by name gracefully.
        $reflection = new \ReflectionMethod($tool, 'execute');
        $hasConfirmedParam = false;

        foreach ($reflection->getParameters() as $param) {
            if ($param->getName() === 'confirmed') {
                $hasConfirmedParam = true;
                break;
            }
        }

        if ($hasConfirmedParam) {
            /** @var CreateTicketTool $tool */
            return $tool->execute($params, $issue, $user, true);
        }

        return $tool->execute($params, $issue, $user);
    }
}
