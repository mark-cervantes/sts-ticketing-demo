<?php

namespace App\Services\Summary\Drivers;

use App\Contracts\SummaryGeneratorInterface;
use App\Exceptions\SummaryGenerationException;
use App\Models\Issue;
use App\Services\Summary\SummaryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * OpenAI-compatible LLM driver for summary generation.
 *
 * Injects HttpFactory so tests can mock via Http::fake() without polluting
 * global state. POSTs to {base_url}/chat/completions with json_object format.
 *
 * @see SRS §7.3
 */
class LlmDriver implements SummaryGeneratorInterface
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * Generate a summary by calling the configured LLM endpoint.
     *
     * @throws SummaryGenerationException on any HTTP, timeout, or parse failure
     */
    public function generate(Issue $issue): SummaryResult
    {
        $baseUrl = rtrim((string) config('summary.drivers.llm.base_url'), '/');
        $apiKey = (string) config('summary.drivers.llm.api_key');
        $model = (string) config('summary.drivers.llm.model');
        $timeout = (int) config('summary.drivers.llm.timeout', 30);

        $prompts = config('prompts.summary');
        $systemMessage = $prompts['system'] ?? 'You are a helpful assistant.';
        $userTemplate = $prompts['user'] ?? '{{title}}\n{{description}}';

        $categoryName = $issue->category?->name ?? 'general';
        $priority = is_object($issue->priority) ? $issue->priority->value : (string) $issue->priority;

        $userMessage = str_replace(
            ['{{category}}', '{{priority}}', '{{title}}', '{{description}}'],
            [$categoryName, $priority, $issue->title ?? '', $issue->description ?? ''],
            $userTemplate,
        );

        try {
            $response = $this->http
                ->withToken($apiKey)
                ->timeout($timeout)
                ->post("$baseUrl/chat/completions", [
                    'model' => $model,
                    'temperature' => 0.3,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemMessage],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new SummaryGenerationException(
                "LLM connection failed: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if ($response->failed()) {
            throw new SummaryGenerationException(
                "LLM API returned HTTP {$response->status()}: {$response->body()}",
            );
        }

        $content = $response->json('choices.0.message.content');

        if (! is_string($content)) {
            throw new SummaryGenerationException(
                'LLM response missing choices[0].message.content field.',
            );
        }

        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            throw new SummaryGenerationException(
                "LLM response content is not valid JSON: $content",
            );
        }

        if (! array_key_exists('summary', $parsed)) {
            throw new SummaryGenerationException(
                "LLM JSON response missing required key 'summary'.",
            );
        }

        if (! array_key_exists('suggested_next_action', $parsed)) {
            throw new SummaryGenerationException(
                "LLM JSON response missing required key 'suggested_next_action'.",
            );
        }

        return new SummaryResult(
            summary: (string) $parsed['summary'],
            suggestedNextAction: (string) $parsed['suggested_next_action'],
            driver: 'llm',
        );
    }
}
