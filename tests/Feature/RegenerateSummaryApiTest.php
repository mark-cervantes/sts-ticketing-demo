<?php

namespace Tests\Feature;

use App\Enums\SummaryStatus;
use App\Jobs\GenerateSummaryJob;
use App\Models\Category;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests for POST /api/issues/{issue}/regenerate-summary
 *
 * Covers:
 *  - Owner can regenerate: resets to pending, clears summary fields, dispatches job, returns 202
 *  - User with view share can regenerate (view permission is sufficient)
 *  - Unauthenticated request returns 401
 *  - User without any access returns 403
 *  - Job is actually dispatched with the correct issue
 */
class RegenerateSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Create an issue with summary_status=ready and a summary present. */
    private function issueWithSummary(User $owner): Issue
    {
        $category = Category::factory()->create();

        return Issue::factory()->create([
            'user_id' => $owner->id,
            'category_id' => $category->id,
            'summary' => 'Existing summary text.',
            'suggested_next_action' => 'Existing action.',
            'summary_status' => SummaryStatus::Ready,
        ]);
    }

    // -------------------------------------------------------------------------
    // Happy paths
    // -------------------------------------------------------------------------

    /** Owner gets 202 and issue fields are reset. */
    public function test_owner_can_regenerate_summary(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $issue = $this->issueWithSummary($owner);

        $response = $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/regenerate-summary");

        $response->assertStatus(202)
            ->assertJsonPath('message', 'Summary regeneration queued');

        $issue->refresh();
        $this->assertSame(SummaryStatus::Pending, $issue->summary_status);
        $this->assertNull($issue->summary);
        $this->assertNull($issue->suggested_next_action);
    }

    /** Owner triggers dispatch of GenerateSummaryJob. */
    public function test_regenerate_dispatches_job(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $issue = $this->issueWithSummary($owner);

        $this->actingAs($owner)
            ->postJson("/api/issues/{$issue->id}/regenerate-summary");

        Queue::assertPushed(GenerateSummaryJob::class, function (GenerateSummaryJob $job) use ($issue) {
            return $job->issue->id === $issue->id;
        });
    }

    /** User who only has view share can also trigger regeneration. */
    public function test_shared_viewer_can_regenerate_summary(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = $this->issueWithSummary($owner);

        IssueShare::factory()->create([
            'issue_id' => $issue->id,
            'user_id' => $viewer->id,
            'permission' => 'view',
        ]);

        $response = $this->actingAs($viewer)
            ->postJson("/api/issues/{$issue->id}/regenerate-summary");

        $response->assertStatus(202);
    }

    // -------------------------------------------------------------------------
    // Auth / access guard
    // -------------------------------------------------------------------------

    /** Unauthenticated request must get 401. */
    public function test_unauthenticated_cannot_regenerate(): void
    {
        $owner = User::factory()->create();
        $issue = $this->issueWithSummary($owner);

        $this->postJson("/api/issues/{$issue->id}/regenerate-summary")
            ->assertStatus(401);
    }

    /** A user with no access to the issue must get 403. */
    public function test_unauthorized_user_cannot_regenerate(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = $this->issueWithSummary($owner);

        $this->actingAs($other)
            ->postJson("/api/issues/{$issue->id}/regenerate-summary")
            ->assertStatus(403);
    }

    /** Job must NOT be dispatched when the request is rejected. */
    public function test_no_job_dispatched_on_unauthorized_request(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = $this->issueWithSummary($owner);

        $this->actingAs($other)
            ->postJson("/api/issues/{$issue->id}/regenerate-summary");

        Queue::assertNothingPushed();
    }
}
