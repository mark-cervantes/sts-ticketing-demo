<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Issue;
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
 * @see task 09.01 / ADR-002
 */
class ChatService
{
    public function __construct(private readonly HttpFactory $http) {}

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
     * Stream a chat response as a Generator of token strings.
     *
     * Yields string tokens as they arrive from the LLM. On error, yields an
     * error JSON string. Always returns after yielding the final token or error.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     * @return \Generator<int, string>
     */
    public function streamChat(Issue $issue, array $history, string $newMessage): \Generator
    {
        $settings = AiSetting::current();
        $messages = $this->buildMessages($issue, $history, $newMessage, $settings);

        $baseUrl = rtrim((string) $settings->effective_base_url, '/');
        $apiKey = (string) $settings->api_key;

        try {
            $response = $this->http
                ->withToken($apiKey)
                ->timeout(60)
                ->withOptions(['stream' => true])
                ->post("{$baseUrl}/chat/completions", [
                    'model' => $settings->model,
                    'temperature' => 0.7,
                    'stream' => true,
                    'messages' => $messages,
                ]);

            if ($response->failed()) {
                yield json_encode(['error' => 'LLM request failed: '.$response->status()]);

                return;
            }

            $body = $response->toPsrResponse()->getBody();
            $buffer = '';

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
                        return;
                    }

                    $parsed = json_decode($data, true);
                    $token = $parsed['choices'][0]['delta']['content'] ?? null;

                    if ($token !== null && $token !== '') {
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
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<int, array{role: string, content: string}>
     */
    public function buildMessages(Issue $issue, array $history, string $newMessage, ?AiSetting $settings = null): array
    {
        $settings ??= AiSetting::current();
        $messages = [];

        // 1. System prompt
        $messages[] = [
            'role' => 'system',
            'content' => $settings->effective_chat_system_prompt,
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
}
