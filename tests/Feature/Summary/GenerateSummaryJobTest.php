<?php

namespace Tests\Feature\Summary;

use App\Enums\SummaryStatus;
use App\Events\SummaryCompleted;
use App\Jobs\GenerateSummaryJob;
use App\Models\Category;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SRS §7.5 / §8.2 I-06, I-07, I-10, I-11 — GenerateSummaryJob behaviour.
 *
 * Uses sync queue so the job actually executes within the test transaction.
 *
 * I-06: job is dispatched when an issue is created (already covered by
 *       IssueCrudApiTest but tested here with job execution for completeness).
 * I-07: job populates summary + suggested_next_action + status=ready.
 * I-10: no LLM API key → auto-fallback to rules driver → status=ready.
 * I-11: LLM returns 500 three times → fallback to rules → status=ready.
 */
class GenerateSummaryJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Run jobs synchronously so assertions fire inside the test transaction.
        Config::set('queue.default', 'sync');

        // Default: rules driver (safe, no HTTP needed).
        Config::set('summary.default', 'rules');
        Config::set('summary.drivers.llm.base_url', 'http://llm.example.test');
        Config::set('summary.drivers.llm.api_key', null);
        Config::set('summary.drivers.llm.model', 'gpt-4o-test');
        Config::set('summary.drivers.llm.timeout', 30);
    }

    // -------------------------------------------------------------------------
    // I-07: job populates issue fields on success
    // -------------------------------------------------------------------------

    /**
     * SRS §8.2 I-07: job sets summary_status=processing at the start of handle().
     *
     * Arrange: issue with summary_status=pending (factory default).
     * Act: dispatch job synchronously.
     * Assert: by the time the test observes the DB the job has completed, so
     *   status should have progressed to 'ready'. The processing step is internal,
     *   but the job MUST write 'processing' then 'ready'.
     */
    public function test_job_sets_summary_status_to_ready_after_successful_run(): void
    {
        $category = Category::factory()->create(['name' => 'technical']);
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->for($category)->create();

        // Dispatch and run synchronously via sync queue.
        dispatch(new GenerateSummaryJob($issue));

        $issue->refresh();
        $this->assertSame(SummaryStatus::Ready, $issue->summary_status);
    }

    /** SRS §8.2 I-07: job populates summary field with non-empty string. */
    public function test_job_populates_summary_field(): void
    {
        $category = Category::factory()->create(['name' => 'billing']);
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->for($category)->create([
            'title' => 'Invoice not delivered for last month',
            'description' => 'Customer reports that the invoice for March 2025 was never emailed despite payment being collected. Other customers on the same plan received theirs.',
        ]);

        dispatch(new GenerateSummaryJob($issue));

        $issue->refresh();
        $this->assertNotNull($issue->summary);
        $this->assertNotEmpty($issue->summary);
    }

    /** SRS §8.2 I-07: job populates suggested_next_action field with non-empty string. */
    public function test_job_populates_suggested_next_action_field(): void
    {
        $category = Category::factory()->create(['name' => 'bug']);
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->for($category)->create([
            'title' => 'Export button crashes on Firefox',
            'description' => 'Clicking the PDF export button on Firefox 124 throws a TypeError. Chrome is unaffected. Console shows "Cannot read properties of undefined (reading \'click\')".',
        ]);

        dispatch(new GenerateSummaryJob($issue));

        $issue->refresh();
        $this->assertNotNull($issue->suggested_next_action);
        $this->assertNotEmpty($issue->suggested_next_action);
    }

    // -------------------------------------------------------------------------
    // SummaryCompleted event
    // -------------------------------------------------------------------------

    /** SRS §7.5: SummaryCompleted event is fired after successful job execution. */
    public function test_summary_completed_event_is_fired_on_success(): void
    {
        Event::fake([SummaryCompleted::class]);

        $category = Category::factory()->create(['name' => 'general']);
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->for($category)->create();

        dispatch(new GenerateSummaryJob($issue));

        Event::assertDispatched(SummaryCompleted::class);
    }

    /** SRS §7.5: SummaryCompleted event carries the updated Issue model. */
    public function test_summary_completed_event_carries_issue(): void
    {
        Event::fake([SummaryCompleted::class]);

        $category = Category::factory()->create(['name' => 'account']);
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->for($category)->create();

        dispatch(new GenerateSummaryJob($issue));

        Event::assertDispatched(SummaryCompleted::class, function (SummaryCompleted $event) use ($issue): bool {
            return $event->issue->id === $issue->id;
        });
    }

    // -------------------------------------------------------------------------
    // I-10: no API key → auto-fallback to rules driver
    // -------------------------------------------------------------------------

    /**
     * SRS §8.2 I-10: when SUMMARY_DRIVER=llm but no LLM_API_KEY is set,
     * SummaryManager auto-falls back to the rules driver at config-resolution time.
     * The job completes successfully with summary_status=ready.
     */
    public function test_job_uses_rules_fallback_when_llm_driver_has_no_api_key(): void
    {
        // Configure llm as requested driver but provide no key.
        Config::set('summary.default', 'llm');
        Config::set('summary.drivers.llm.api_key', null);

        // Http should NOT be called — the manager falls back before making any request.
        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $category = Category::factory()->create(['name' => 'technical']);
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->for($category)->create([
            'title' => 'API rate limit exceeded during business hours',
            'description' => 'The rate limit on the public API hits 429 consistently between 09:00 and 11:00 UTC, blocking automated workflows.',
        ]);

        dispatch(new GenerateSummaryJob($issue));

        $issue->refresh();
        $this->assertSame(SummaryStatus::Ready, $issue->summary_status);
        $this->assertNotEmpty($issue->summary);

        // Confirm rules driver ran — no HTTP request should have been made.
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // I-11: LLM HTTP failures → retry → fallback to rules
    // -------------------------------------------------------------------------

    /**
     * SRS §8.2 I-11: when the LLM endpoint returns 500 and all retries are
     * exhausted, the job falls back to the rules driver and sets status=ready.
     *
     * In a sync-queue test, retries do not re-queue. We simulate the final-attempt
     * fallback by having Http::fake() always return 500 and asserting that the
     * job uses the rules driver as a fallback catch, resulting in status=ready.
     */
    public function test_job_falls_back_to_rules_driver_after_llm_failure(): void
    {
        Config::set('summary.default', 'llm');
        Config::set('summary.drivers.llm.api_key', 'sk-valid-key-but-service-is-down');

        // LLM endpoint always returns 500 — job must catch and use rules fallback.
        Http::fake([
            'llm.example.test/chat/completions' => Http::response([], 500),
            '*' => Http::response([], 500),
        ]);

        $category = Category::factory()->create(['name' => 'bug']);
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->for($category)->create([
            'title' => 'Webhook delivery fails for Stripe events',
            'description' => 'All Stripe webhooks are returning 500 from our endpoint since the last deploy. Logs show the signature validation middleware is throwing.',
        ]);

        dispatch(new GenerateSummaryJob($issue));

        $issue->refresh();
        $this->assertSame(SummaryStatus::Ready, $issue->summary_status);
        $this->assertNotEmpty($issue->summary);
        $this->assertNotEmpty($issue->suggested_next_action);
    }

    // -------------------------------------------------------------------------
    // Permanent failure → status=failed
    // -------------------------------------------------------------------------

    /**
     * SRS §7.5 / ADR-002 line 71: if the job's failed() method fires (all retries
     * exhausted and no fallback caught), summary_status is set to 'failed'.
     *
     * We test this by calling failed() directly on the job instance, simulating
     * what Laravel's queue runner calls when the job cannot be salvaged.
     */
    public function test_failed_method_sets_summary_status_to_failed(): void
    {
        $category = Category::factory()->create(['name' => 'general']);
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->for($category)->create();

        $job = new GenerateSummaryJob($issue);

        // Simulate Laravel calling failed() after exhausting all retries.
        $job->failed(new \Exception('Simulated unrecoverable failure after all retries.'));

        $issue->refresh();
        $this->assertSame(SummaryStatus::Failed, $issue->summary_status);
    }

    // -------------------------------------------------------------------------
    // Constructor contract — must not change (pitfall from tech guidance)
    // -------------------------------------------------------------------------

    /**
     * Confirm GenerateSummaryJob accepts a single Issue argument.
     * This guards the constructor contract that IssueService and existing tests rely on.
     */
    public function test_job_constructor_accepts_issue_model(): void
    {
        $category = Category::factory()->create();
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->for($category)->create();

        // Must not throw — constructor must accept an Issue.
        $job = new GenerateSummaryJob($issue);

        $this->assertSame($issue->id, $job->issue->id);
    }
}
