<?php

namespace Tests\Unit\Summary;

use App\Exceptions\SummaryGenerationException;
use App\Models\Category;
use App\Models\Issue;
use App\Services\Summary\Drivers\LlmDriver;
use App\Services\Summary\SummaryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SRS §7.3 — LlmDriver: HTTP call, JSON parsing, and failure paths.
 *
 * No DB — Issue instances built with make() and relations set manually.
 * LlmDriver receives a constructor-injected HttpFactory so Http::fake() controls
 * its responses without global state pollution.
 *
 * All tests expect LlmDriver and SummaryGenerationException to exist (task 02.04.00).
 */
class LlmDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('summary.drivers.llm.base_url', 'http://llm.example.test');
        Config::set('summary.drivers.llm.api_key', 'sk-test-key-abc123');
        Config::set('summary.drivers.llm.model', 'gpt-4o-test');
        Config::set('summary.drivers.llm.timeout', 30);
    }

    /**
     * Build an Issue model instance in-memory (no DB) with a category relation
     * pre-loaded, so the driver's category-name access does not fail.
     */
    private function makeIssue(
        string $title = 'Login page throws 500 on empty email',
        string $description = 'Submitting login form with blank email field reproduces consistently.',
        string $categoryName = 'technical',
        string $priority = 'high',
    ): Issue {
        $category = new Category(['name' => $categoryName]);
        $category->id = 1;

        $issue = Issue::make([
            'title' => $title,
            'description' => $description,
            'priority' => $priority,
        ]);

        // Set relation manually — no DB roundtrip needed for unit tests.
        $issue->setRelation('category', $category);

        return $issue;
    }

    /** SRS §7.3: driver returns SummaryResult when API responds with valid JSON object. */
    public function test_returns_summary_result_on_valid_json_response(): void
    {
        Http::fake([
            'llm.example.test/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'User reports 500 error on the login page when submitting with empty email.',
                                'suggested_next_action' => 'Add server-side email validation before attempting authentication.',
                                'suggested_next_ticket' => 'Add login validation tests — Write tests covering empty and invalid email edge cases.',
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $driver = new LlmDriver($this->app->make(HttpFactory::class));
        $result = $driver->generate($this->makeIssue());

        $this->assertInstanceOf(SummaryResult::class, $result);
        $this->assertNotEmpty($result->summary);
        $this->assertNotEmpty($result->suggestedNextAction);
        $this->assertSame('llm', $result->driver);
    }

    /** SRS §7.3: driver throws SummaryGenerationException on HTTP 500. */
    public function test_throws_on_http_500(): void
    {
        Http::fake([
            'llm.example.test/chat/completions' => Http::response([], 500),
        ]);

        $driver = new LlmDriver($this->app->make(HttpFactory::class));

        $this->expectException(SummaryGenerationException::class);

        $driver->generate($this->makeIssue());
    }

    /** SRS §7.3: driver throws SummaryGenerationException on connection timeout. */
    public function test_throws_on_connection_timeout(): void
    {
        Http::fake([
            'llm.example.test/chat/completions' => function (Request $request) {
                throw new ConnectionException('Connection timed out.');
            },
        ]);

        $driver = new LlmDriver($this->app->make(HttpFactory::class));

        $this->expectException(SummaryGenerationException::class);

        $driver->generate($this->makeIssue());
    }

    /** SRS §7.3: driver throws SummaryGenerationException when response content is not valid JSON. */
    public function test_throws_on_malformed_json_in_content(): void
    {
        Http::fake([
            'llm.example.test/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'not-valid-json-at-all{{',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $driver = new LlmDriver($this->app->make(HttpFactory::class));

        $this->expectException(SummaryGenerationException::class);

        $driver->generate($this->makeIssue());
    }

    /** SRS §7.3: driver throws SummaryGenerationException when JSON is missing the 'summary' key. */
    public function test_throws_when_summary_key_is_missing_from_json(): void
    {
        Http::fake([
            'llm.example.test/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'suggested_next_action' => 'Check the logs.',
                                // 'summary' intentionally absent
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $driver = new LlmDriver($this->app->make(HttpFactory::class));

        $this->expectException(SummaryGenerationException::class);

        $driver->generate($this->makeIssue());
    }

    /** SRS §7.3: driver throws SummaryGenerationException when JSON is missing 'suggested_next_action'. */
    public function test_throws_when_suggested_next_action_key_is_missing_from_json(): void
    {
        Http::fake([
            'llm.example.test/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'Some summary text here.',
                                // 'suggested_next_action' intentionally absent
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $driver = new LlmDriver($this->app->make(HttpFactory::class));

        $this->expectException(SummaryGenerationException::class);

        $driver->generate($this->makeIssue());
    }

    /** SRS §7.3: driver throws SummaryGenerationException on HTTP 401 (bad credentials). */
    public function test_throws_on_http_401_unauthorized(): void
    {
        Http::fake([
            'llm.example.test/chat/completions' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $driver = new LlmDriver($this->app->make(HttpFactory::class));

        $this->expectException(SummaryGenerationException::class);

        $driver->generate($this->makeIssue());
    }
}
