<?php

namespace Tests\Feature\Console;

use App\Enums\SummaryStatus;
use App\Jobs\GenerateSummaryJob;
use App\Models\Issue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for summaries:retry-stuck command.
 *
 * Uses Queue::fake() so GenerateSummaryJob is captured without executing.
 * RefreshDatabase ensures test isolation — each test starts with a clean DB.
 *
 * @see SPEC §4.2 / ADR-002 / SummaryStatus
 */
class RetrySummariesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent jobs from running — we only assert they are dispatched.
        Queue::fake();
    }

    // -------------------------------------------------------------------------
    // processing → reset → dispatched
    // -------------------------------------------------------------------------

    /**
     * Issues stuck in `processing` (interrupted mid-job) must be reset to
     * `pending` and a new GenerateSummaryJob must be dispatched for each.
     */
    public function test_processing_issues_are_reset_and_dispatched(): void
    {
        $issue = Issue::factory()->summaryProcessing()->create();

        $this->artisan('summaries:retry-stuck')
            ->expectsOutput('Retried 1 stuck summaries.')
            ->assertExitCode(0);

        // Verify the DB was reset to pending.
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'summary_status' => SummaryStatus::Pending->value,
        ]);

        // Verify a job was dispatched for this issue.
        Queue::assertPushed(GenerateSummaryJob::class, function (GenerateSummaryJob $job) use ($issue): bool {
            return $job->issue->id === $issue->id;
        });
    }

    // -------------------------------------------------------------------------
    // pending → dispatched (no reset needed)
    // -------------------------------------------------------------------------

    /**
     * Issues already in `pending` (never picked up) must have a job dispatched
     * without any status change.
     */
    public function test_pending_issues_are_dispatched(): void
    {
        $issue = Issue::factory()->create(); // factory default is Pending

        $this->artisan('summaries:retry-stuck')
            ->expectsOutput('Retried 1 stuck summaries.')
            ->assertExitCode(0);

        Queue::assertPushed(GenerateSummaryJob::class, function (GenerateSummaryJob $job) use ($issue): bool {
            return $job->issue->id === $issue->id;
        });
    }

    // -------------------------------------------------------------------------
    // ready → skipped
    // -------------------------------------------------------------------------

    /**
     * Issues with `ready` summary status must NOT be dispatched again.
     * They already have a completed summary.
     */
    public function test_ready_issues_are_skipped(): void
    {
        Issue::factory()->summaryReady()->create();

        $this->artisan('summaries:retry-stuck')
            ->expectsOutput('Retried 0 stuck summaries.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // failed → skipped
    // -------------------------------------------------------------------------

    /**
     * Issues with `failed` summary status must NOT be dispatched.
     * Failed issues require explicit human intervention, not an automatic retry.
     */
    public function test_failed_issues_are_skipped(): void
    {
        Issue::factory()->summaryFailed()->create();

        $this->artisan('summaries:retry-stuck')
            ->expectsOutput('Retried 0 stuck summaries.')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------------------------
    // idempotency
    // -------------------------------------------------------------------------

    /**
     * Running the command twice produces the same outcome as running it once.
     *
     * After the first run all issues are `pending` (with jobs dispatched).
     * Queue::fake() captures — no job ever executes — so on the second run
     * those issues are still `pending` and jobs are dispatched again.
     * Crucially: no issue is double-counted per run, and no error occurs.
     */
    public function test_command_is_idempotent(): void
    {
        $issueA = Issue::factory()->summaryProcessing()->create();
        $issueB = Issue::factory()->create(); // pending

        // First run.
        $this->artisan('summaries:retry-stuck')
            ->expectsOutput('Retried 2 stuck summaries.')
            ->assertExitCode(0);

        Queue::assertPushedTimes(GenerateSummaryJob::class, 2);

        // Both issues are now pending in DB (issueA was reset, issueB was always pending).
        $this->assertDatabaseHas('issues', ['id' => $issueA->id, 'summary_status' => SummaryStatus::Pending->value]);
        $this->assertDatabaseHas('issues', ['id' => $issueB->id, 'summary_status' => SummaryStatus::Pending->value]);

        // Second run — should succeed without errors and dispatch again.
        $this->artisan('summaries:retry-stuck')
            ->expectsOutput('Retried 2 stuck summaries.')
            ->assertExitCode(0);

        // 4 total dispatches across both runs (2 per run).
        Queue::assertPushedTimes(GenerateSummaryJob::class, 4);
    }

    // -------------------------------------------------------------------------
    // mixed statuses — correct count
    // -------------------------------------------------------------------------

    /**
     * With a mix of all four statuses, only pending and processing issues
     * are retried. The output count reflects only those that were dispatched.
     */
    public function test_correct_count_with_mixed_statuses(): void
    {
        Issue::factory()->summaryProcessing()->create(); // → reset + dispatched
        Issue::factory()->create();                      // pending → dispatched
        Issue::factory()->summaryReady()->create();      // skipped
        Issue::factory()->summaryFailed()->create();     // skipped

        $this->artisan('summaries:retry-stuck')
            ->expectsOutput('Retried 2 stuck summaries.')
            ->assertExitCode(0);

        Queue::assertPushedTimes(GenerateSummaryJob::class, 2);
    }
}
