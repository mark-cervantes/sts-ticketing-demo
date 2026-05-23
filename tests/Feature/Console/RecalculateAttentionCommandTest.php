<?php

namespace Tests\Feature\Console;

use App\Enums\Priority;
use App\Models\Issue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for issues:recalculate-attention command.
 *
 * Fixture strategy: the Issue saving event always recomputes needs_attention on
 * create/update, so test data that needs a "stale" flag value must be forced via
 * a raw DB::table()->update() after factory creation.  This accurately simulates
 * the real scenario — a flag that was correct at create time but has since become
 * stale because time has passed.
 *
 * @see SPEC §6.6 / ADR-005 / BR-03
 */
class RecalculateAttentionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic config — 60-minute window, consistent with IssueTest.
        Config::set('issues.attention_threshold_minutes', 60);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Force needs_attention to false in the DB without triggering model events.
     * Simulates a stale flag — the issue was created before its deadline became urgent.
     */
    private function staleFlag(Issue $issue): void
    {
        DB::table('issues')->where('id', $issue->id)->update(['needs_attention' => false]);
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    /**
     * An open issue with a deadline within the attention window should have
     * needs_attention flipped to true by the command.
     */
    public function test_open_issue_with_approaching_deadline_gets_flagged(): void
    {
        // Create open, low-priority issue with deadline in 30 min (within 60-min window).
        // The saving event will compute needs_attention = true at create time.
        // We then stale it to false to simulate the pre-run state the command must fix.
        $issue = Issue::factory()->open()->create([
            'priority' => Priority::Low,
            'deadline_at' => now()->addMinutes(30),
        ]);
        $this->staleFlag($issue);

        $this->artisan('issues:recalculate-attention')
            ->expectsOutput('Updated 1 issues.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'needs_attention' => true,
        ]);
    }

    /**
     * An in-progress issue with a deadline far in the future and non-critical
     * priority should not be updated — needs_attention stays false.
     */
    public function test_issue_with_far_deadline_stays_unflagged(): void
    {
        // Saving event computes needs_attention = false (far deadline + low priority) — no stale needed.
        $issue = Issue::factory()->inProgress()->create([
            'priority' => Priority::Low,
            'deadline_at' => now()->addDays(10),
        ]);

        $this->artisan('issues:recalculate-attention')
            ->expectsOutput('Updated 0 issues.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'needs_attention' => false,
        ]);
    }

    /**
     * A resolved issue with an approaching deadline must NOT be updated,
     * even though its deadline would normally trigger needs_attention.
     */
    public function test_resolved_issue_with_approaching_deadline_is_excluded(): void
    {
        $issue = Issue::factory()->resolved()->create([
            'priority' => Priority::Low,
            'deadline_at' => now()->addMinutes(10),
        ]);
        // Stale the flag so we can detect any unwanted write.
        $this->staleFlag($issue);

        $this->artisan('issues:recalculate-attention')
            ->expectsOutput('Updated 0 issues.')
            ->assertExitCode(0);

        // Still false — the command must not have touched it.
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'needs_attention' => false,
        ]);
    }

    /**
     * A soft-deleted issue must NOT be updated by the command.
     * SoftDeletes on the Issue model already excludes trashed rows from queries.
     */
    public function test_soft_deleted_issue_is_excluded(): void
    {
        $issue = Issue::factory()->open()->create([
            'priority' => Priority::Low,
            'deadline_at' => now()->addMinutes(10),
        ]);
        $this->staleFlag($issue);
        $issue->delete(); // soft-delete

        $this->artisan('issues:recalculate-attention')
            ->expectsOutput('Updated 0 issues.')
            ->assertExitCode(0);

        // Assert directly on the trashed row (withTrashed to read it).
        $this->assertDatabaseHas('issues', [
            'id' => $issue->id,
            'needs_attention' => false,
        ]);
    }

    /**
     * The command reports the correct count when multiple issues are updated.
     */
    public function test_command_outputs_correct_updated_count(): void
    {
        // Two open issues within the attention window — staled to false.
        $issueA = Issue::factory()->open()->create([
            'priority' => Priority::Low,
            'deadline_at' => now()->addMinutes(20),
        ]);
        $issueB = Issue::factory()->open()->create([
            'priority' => Priority::Low,
            'deadline_at' => now()->addMinutes(45),
        ]);
        $this->staleFlag($issueA);
        $this->staleFlag($issueB);

        // One in-progress issue outside the window — needs_attention already false, no stale needed.
        Issue::factory()->inProgress()->create([
            'priority' => Priority::Low,
            'deadline_at' => now()->addDays(5),
        ]);

        $this->artisan('issues:recalculate-attention')
            ->expectsOutput('Updated 2 issues.')
            ->assertExitCode(0);
    }

    /**
     * An issue whose needs_attention flag is already correct should NOT trigger a DB write.
     * (Flag is true and condition is true → no change → count = 0.)
     */
    public function test_already_correct_flag_is_not_written(): void
    {
        // Saving event sets needs_attention = true (approaching deadline).
        // No staling — flag is already correct.
        Issue::factory()->open()->create([
            'priority' => Priority::Low,
            'deadline_at' => now()->addMinutes(30),
        ]);

        $this->artisan('issues:recalculate-attention')
            ->expectsOutput('Updated 0 issues.')
            ->assertExitCode(0);
    }
}
