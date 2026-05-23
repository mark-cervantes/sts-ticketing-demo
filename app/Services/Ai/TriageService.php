<?php

namespace App\Services\Ai;

use App\Models\AiSetting;
use App\Models\Category;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * AI triage service — provides one-shot priority + category suggestions.
 *
 * Respects ADR-002: all HTTP calls to LLM endpoints live exclusively here,
 * never in controllers or jobs.
 *
 * Two paths:
 *  - rules driver  → deterministic keyword heuristic, no network call
 *  - llm driver    → calls the configured OpenAI-compatible endpoint; any
 *                    exception falls back to the heuristic automatically
 *
 * @see task 08.04 / ADR-002
 */
class TriageService
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Suggest priority and category for a new issue.
     *
     * @return array{priority: string, category_id: int|null, category_name: string, confidence: string}
     */
    public function suggest(string $title, string $description): array
    {
        $settings = AiSetting::current();

        if ($settings->effective_driver === 'rules') {
            return $this->heuristicTriage($title, $description);
        }

        try {
            return $this->llmTriage($title, $description, $settings);
        } catch (\Throwable) {
            return $this->heuristicTriage($title, $description);
        }
    }

    // -------------------------------------------------------------------------
    // Heuristic (rules) path
    // -------------------------------------------------------------------------

    /**
     * Deterministic keyword-based triage — no network call, always succeeds.
     *
     * @return array{priority: string, category_id: int|null, category_name: string, confidence: string}
     */
    private function heuristicTriage(string $title, string $description): array
    {
        $text = strtolower($title.' '.$description);

        $priority = $this->detectPriority($text);
        [$categoryId, $categoryName] = $this->detectCategory($text);

        return [
            'priority' => $priority,
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'confidence' => 'heuristic',
        ];
    }

    /**
     * Detect priority from lowercased combined text.
     */
    private function detectPriority(string $text): string
    {
        if (preg_match('/\b(crash|down|outage|emergency|critical|urgent|security|breach|data loss)\b/', $text)) {
            return 'critical';
        }

        if (preg_match('/\b(broken|failing|error|bug|cannot|blocked|stopped)\b/', $text)) {
            return 'high';
        }

        if (preg_match('/\b(slow|minor|cosmetic|typo|wish|could|suggestion)\b/', $text)) {
            return 'low';
        }

        return 'medium';
    }

    /**
     * Detect category from lowercased combined text.
     *
     * @return array{0: int|null, 1: string}
     */
    private function detectCategory(string $text): array
    {
        $categories = Category::pluck('id', 'name')->toArray();

        $categoryKeywords = [
            'billing' => ['billing', 'invoice', 'payment', 'charge', 'subscription', 'refund', 'price'],
            'technical' => ['api', 'server', 'database', 'deploy', 'infrastructure', 'performance', 'latency', 'timeout'],
            'account' => ['account', 'login', 'password', 'permission', 'access', 'profile', 'user', 'auth'],
            'bug' => ['bug', 'error', 'crash', 'broken', 'fix', 'defect', 'regression', 'not working'],
            'feature-request' => ['feature', 'request', 'add', 'new', 'enhance', 'improve', 'wish', 'could you'],
        ];

        foreach ($categoryKeywords as $catName => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword) && isset($categories[$catName])) {
                    return [$categories[$catName], $catName];
                }
            }
        }

        // Default to first category in DB when no keyword matches
        $first = Category::first();

        return [$first?->id, $first?->name ?? 'general'];
    }

    // -------------------------------------------------------------------------
    // LLM path
    // -------------------------------------------------------------------------

    /**
     * Ask the configured LLM endpoint for priority + category suggestions.
     *
     * @return array{priority: string, category_id: int|null, category_name: string, confidence: string}
     *
     * @throws \RuntimeException on HTTP failure or unparseable response
     */
    private function llmTriage(string $title, string $description, AiSetting $settings): array
    {
        $categories = Category::pluck('name')->toArray();
        $categoryList = implode(', ', $categories);

        $systemPrompt = <<<PROMPT
You are a support ticket triage assistant. Given a ticket's title and description, suggest:
1. A priority level (one of: low, medium, high, critical)
2. A category (one of: {$categoryList})

Respond ONLY with valid JSON: {"priority": "...", "category": "..."}
PROMPT;

        $userMessage = "Title: {$title}\nDescription: {$description}";

        $response = $this->http
            ->withToken((string) $settings->api_key)
            ->timeout(15)
            ->post(rtrim((string) $settings->effective_base_url, '/').'/chat/completions', [
                'model' => $settings->model,
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userMessage],
                ],
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('LLM triage failed: '.$response->status());
        }

        $content = $response->json('choices.0.message.content');
        $parsed = json_decode((string) $content, true);

        if (! $parsed || ! isset($parsed['priority'], $parsed['category'])) {
            throw new \RuntimeException('Invalid LLM triage response');
        }

        $category = Category::where('name', $parsed['category'])->first();

        return [
            'priority' => $parsed['priority'],
            'category_id' => $category?->id ?? Category::first()?->id,
            'category_name' => $parsed['category'],
            'confidence' => 'ai',
        ];
    }
}
