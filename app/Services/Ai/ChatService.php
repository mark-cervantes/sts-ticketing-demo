<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Issue;
use App\Services\Ai\Tools\ChatToolRegistry;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * AI chat service — provides streaming conversation responses about issues.
 *
 * Respects ADR-002: all HTTP calls to LLM endpoints live exclusively here,
 * never in controllers or jobs.
 *
 * The stateless `streamChat()` method accepts a history array (from client
 * session or DB) and a new user message, builds the LLM context, and yields
 * token content deltas from the streaming response.
 *
 * Context window construction:
 *  1. System prompt from AiSetting::current()->effective_chat_system_prompt
 *  2. Issue context block: title, description, category, priority, status, last 10 comments
 *  3. Chat history (capped at last 20 messages)
 *  4. New user message
 *
 * Tool support (task 09.04):
 *  - When ChatToolRegistry has registered tools, adds `tools` param to the LLM request
 *    (OpenAI function-calling format).
 *  - When the LLM responds with delta.tool_calls, accumulates fragmented argument chunks
 *    and yields a structured tool_call event instead of a plain token.
 *  - Fallback for non-function-calling models (Ollama / rules driver): appends tool
 *    descriptions to the system prompt and scans the streamed text for a
 *    `{"tool_call":{"name":"...","arguments":{...}}}` JSON block.
 *
 * @see task 09.01 / task 09.04 / ADR-002
 */
class ChatService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ChatToolRegistry $registry,
    ) {}

    /**
     * Check whether the AI provider is properly configured (base_url + api_key present).
     *
     * Returns false when the provider is 'rules' or when required credentials
     * are missing — controllers should return 503 before opening a stream.
     */
    public function isConfigured(): bool
    {
        $settings = AiSetting::current();

        if ($settings->effective_driver === 'rules') {
            return false;
        }

        $baseUrl = $settings->effective_base_url;
        $apiKey = $settings->api_key;

        return ! empty($baseUrl) && ! empty($apiKey);
    }

    /**
     * Stream a chat response as a Generator of token strings or tool-call arrays.
     *
     * Yields either:
     *  - string  — a plain text token delta
     *  - array   — a structured tool_call event:
     *              ['type' => 'tool_call', 'tool' => $name, 'arguments' => $args,
     *               'requires_confirmation' => bool]
     *
     * On error, yields a JSON error string. Always returns after yielding the
     * final token or error.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return \Generator<int, string|array<string, mixed>>
     */
    public function streamChat(Issue $issue, array $history, string $newMessage): \Generator
    {
        $settings = AiSetting::current();
        $useNativeFunctionCalling = $this->supportsNativeFunctionCalling($settings);
        $messages = $this->buildMessages($issue, $history, $newMessage, $settings, $useNativeFunctionCalling);

        $baseUrl = rtrim((string) $settings->effective_base_url, '/');
        $apiKey = (string) $settings->api_key;

        $payload = [
            'model' => $settings->model,
            'temperature' => 0.7,
            'stream' => true,
            'messages' => $messages,
        ];

        // Add tools to payload only when native function-calling is supported and
        // the registry is not empty. Some providers reject an empty tools array.
        if ($useNativeFunctionCalling && ! $this->registry->isEmpty()) {
            $payload['tools'] = $this->registry->getToolDefinitions();
        }

        try {
            $response = $this->http
                ->withToken($apiKey)
                ->timeout(60)
                ->withOptions(['stream' => true])
                ->post("{$baseUrl}/chat/completions", $payload);

            if ($response->failed()) {
                yield json_encode(['error' => 'LLM request failed: '.$response->status()]);

                return;
            }

            $body = $response->toPsrResponse()->getBody();
            $buffer = '';

            // Accumulated tool-call state across fragmented SSE chunks
            $toolCallName = null;
            $toolCallArgsBuf = '';
            $fullResponseText = '';

            while (! $body->eof()) {
                $chunk = $body->read(1024);
                $buffer .= $chunk;

                // Process complete lines from the buffer
                while (($newlinePos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newlinePos);
                    $buffer = substr($buffer, $newlinePos + 1);

                    $line = trim($line);

                    if ($line === '' || str_starts_with($line, ':')) {
                        continue;
                    }

                    if (! str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $data = substr($line, 6);

                    if ($data === '[DONE]') {
                        // If we accumulated a tool call, yield it now
                        if ($toolCallName !== null) {
                            yield from $this->yieldToolCallEvent($toolCallName, $toolCallArgsBuf);
                        } elseif (! $useNativeFunctionCalling && ! $this->registry->isEmpty()) {
                            // Fallback: scan full response for embedded JSON tool_call
                            yield from $this->extractFallbackToolCall($fullResponseText);
                        }

                        return;
                    }

                    $parsed = json_decode($data, true);

                    if (! is_array($parsed)) {
                        continue;
                    }

                    $delta = $parsed['choices'][0]['delta'] ?? [];

                    // Check for native function-calling tool_calls delta
                    $toolCallsDelta = $delta['tool_calls'] ?? null;

                    if ($toolCallsDelta !== null) {
                        foreach ($toolCallsDelta as $tcChunk) {
                            if (isset($tcChunk['function']['name']) && $tcChunk['function']['name'] !== '') {
                                $toolCallName = $tcChunk['function']['name'];
                            }

                            if (isset($tcChunk['function']['arguments'])) {
                                $toolCallArgsBuf .= $tcChunk['function']['arguments'];
                            }
                        }

                        continue; // tool_call chunks don't have content
                    }

                    // Regular content delta
                    $token = $delta['content'] ?? null;

                    if ($token !== null && $token !== '') {
                        $fullResponseText .= $token;
                        yield $token;
                    }
                }
            }
        } catch (\Throwable $e) {
            yield json_encode(['error' => 'Chat stream error: '.$e->getMessage()]);
        }
    }

    /**
     * Build the messages array for the LLM API call.
     *
     * When useNativeFunctionCalling is false and the registry has tools, appends
     * fallback tool instructions to the system prompt so Ollama-style models can
     * indicate a tool call via a JSON block in their response.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    public function buildMessages(
        Issue $issue,
        array $history,
        string $newMessage,
        ?AiSetting $settings = null,
        bool $useNativeFunctionCalling = true,
    ): array {
        $settings ??= AiSetting::current();
        $messages = [];

        // 1. System prompt (with optional fallback tool instructions)
        $systemPrompt = $settings->effective_chat_system_prompt;

        if (! $useNativeFunctionCalling && ! $this->registry->isEmpty()) {
            $systemPrompt .= $this->buildFallbackToolInstructions();
        }

        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        // 2. Issue context block
        $messages[] = [
            'role' => 'user',
            'content' => $this->buildIssueContext($issue),
        ];

        // 3. Chat history — capped at last 20 messages
        $cappedHistory = array_slice($history, -20);

        foreach ($cappedHistory as $entry) {
            $messages[] = [
                'role' => $entry['role'],
                'content' => $entry['content'],
            ];
        }

        // 4. New user message
        $messages[] = [
            'role' => 'user',
            'content' => $newMessage,
        ];

        return $messages;
    }

    /**
     * Build the issue context string including recent comments.
     *
     * Loads the last 10 comments sorted by created_at.
     */
    private function buildIssueContext(Issue $issue): string
    {
        $issue->loadMissing('category', 'status', 'comments.user');

        $categoryName = $issue->category?->name ?? 'general';
        $priority = is_object($issue->priority) ? $issue->priority->value : (string) $issue->priority;
        $statusName = $issue->status?->name ?? 'unknown';

        $recentComments = $issue->comments
            ->sortBy('created_at')
            ->slice(-10)
            ->map(fn ($c) => sprintf(
                '[%s] %s: %s',
                $c->created_at->format('M d H:i'),
                $c->user?->name ?? 'System',
                $c->body,
            ))
            ->implode("\n");

        if (empty($recentComments)) {
            $recentComments = '(No comments yet)';
        }

        return <<<CONTEXT
        Issue Context:
        Title: {$issue->title}
        Description: {$issue->description}
        Category: {$categoryName}
        Priority: {$priority}
        Status: {$statusName}

        Recent Comments:
        {$recentComments}
        CONTEXT;
    }

    // -------------------------------------------------------------------------
    // Tool-call helpers
    // -------------------------------------------------------------------------

    /**
     * Whether the current AI settings driver supports native function-calling.
     *
     * OpenAI-compatible endpoints (openai, openrouter, custom) support it.
     * The rules driver and Ollama (without explicit function-calling support) do not.
     *
     * Note: effective_driver is always 'rules' or 'llm'. We inspect the provider
     * field directly to detect Ollama (which uses the 'llm' effective driver but
     * does not reliably support the `tools` parameter).
     */
    private function supportsNativeFunctionCalling(AiSetting $settings): bool
    {
        // Rules driver has no LLM at all
        if ($settings->effective_driver === 'rules') {
            return false;
        }

        // Ollama provider uses prompt-based fallback since function-calling support
        // varies by model and is not guaranteed.
        if ($settings->provider === 'ollama') {
            return false;
        }

        return true;
    }

    /**
     * Build fallback tool instructions appended to the system prompt for models
     * that don't support native function-calling (e.g. Ollama).
     *
     * Instructs the model to output a specific JSON block when it wants to invoke
     * a tool. The streaming response is then scanned for this pattern.
     */
    private function buildFallbackToolInstructions(): string
    {
        $toolList = '';

        foreach ($this->registry->getToolDefinitions() as $def) {
            $fn = $def['function'];
            $params = json_encode($fn['parameters'], JSON_PRETTY_PRINT);
            $toolList .= "\n- {$fn['name']}: {$fn['description']}\n  Parameters: {$params}";
        }

        return <<<INSTRUCTIONS


        You have access to the following tools:{$toolList}

        When you want to use a tool, output ONLY the following JSON on a standalone line (no markdown fences):
        {"tool_call": {"name": "<tool_name>", "arguments": {<arguments>}}}

        Do not include any other text on that line. Precede the JSON line with your explanation if needed.
        INSTRUCTIONS;
    }

    /**
     * Yield a structured tool_call generator event from accumulated name + args buffer.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function yieldToolCallEvent(string $name, string $argsBuf): \Generator
    {
        $args = [];

        if ($argsBuf !== '') {
            $decoded = json_decode($argsBuf, true);

            if (is_array($decoded)) {
                $args = $decoded;
            }
        }

        $requiresConfirmation = $this->registry->requiresConfirmation($name);

        yield [
            'type' => 'tool_call',
            'tool' => $name,
            'arguments' => $args,
            'requires_confirmation' => $requiresConfirmation,
        ];
    }

    /**
     * Scan a full streamed response text for the fallback tool_call JSON pattern.
     *
     * Looks for lines containing `{"tool_call": {...}}` and tries to decode them.
     * Handles nested objects by scanning each line rather than using a brittle regex.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function extractFallbackToolCall(string $fullText): \Generator
    {
        foreach (explode("\n", $fullText) as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '{"tool_call":')) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (! is_array($decoded) || ! isset($decoded['tool_call']['name'])) {
                continue;
            }

            $name = $decoded['tool_call']['name'];
            $args = $decoded['tool_call']['arguments'] ?? [];

            yield [
                'type' => 'tool_call',
                'tool' => $name,
                'arguments' => is_array($args) ? $args : [],
                'requires_confirmation' => $this->registry->hasTool($name),
            ];

            return; // Only handle the first tool_call found
        }
    }
}
