<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for POST /api/statuses/{status}/migrate-and-delete.
 *
 * Covers: migrate issues to target, delete issues in bulk, 409 for default status,
 * 422 when neither option provided, 422 when target does not exist.
 *
 * @see task 08.02 / SRS §FR-02
 */
class MigrateAndDeleteStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a non-default test status (StatusFactory is named StatusFactory, not IssueStatusFactory).
     */
    private function makeStatus(string $name = 'Test Status', int $sortOrder = 50): IssueStatus
    {
        return IssueStatus::create([
            'name' => $name,
            'color' => '#64748b',
            'sort_order' => $sortOrder,
            'is_default' => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // Auth guard
    // -------------------------------------------------------------------------

    /** Unauthenticated requests are rejected. */
    public function test_unauthenticated_user_cannot_call_migrate_and_delete(): void
    {
        $status = $this->makeStatus();

        $this->postJson("/api/statuses/{$status->id}/migrate-and-delete", [])
            ->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // 409 — default status guard
    // -------------------------------------------------------------------------

    /** Attempting to delete the default status returns 409. */
    public function test_returns_409_when_trying_to_delete_default_status(): void
    {
        $user = User::factory()->create();
        $default = IssueStatus::where('is_default', true)->first();

        $this->actingAs($user)
            ->postJson("/api/statuses/{$default->id}/migrate-and-delete", [
                'delete_issues' => true,
            ])
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Cannot delete the default status.']);

        $this->assertDatabaseHas('statuses', ['id' => $default->id]);
    }

    // -------------------------------------------------------------------------
    // 422 — validation errors
    // -------------------------------------------------------------------------

    /** When the status has issues, caller must provide target_status_id or delete_issues. */
    public function test_returns_422_when_status_has_issues_and_no_option_provided(): void
    {
        $user = User::factory()->create();
        $status = $this->makeStatus();
        Issue::factory()->for($status, 'status')->count(2)->create();

        $this->actingAs($user)
            ->postJson("/api/statuses/{$status->id}/migrate-and-delete", [])
            ->assertStatus(422);

        $this->assertDatabaseHas('statuses', ['id' => $status->id]);
    }

    /** target_status_id that does not exist in the DB returns 422. */
    public function test_returns_422_when_target_status_id_does_not_exist(): void
    {
        $user = User::factory()->create();
        $status = $this->makeStatus();
        Issue::factory()->for($status, 'status')->create();

        $this->actingAs($user)
            ->postJson("/api/statuses/{$status->id}/migrate-and-delete", [
                'target_status_id' => 99999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_status_id']);
    }

    /** target_status_id cannot be the same as the status being deleted. */
    public function test_returns_422_when_target_status_id_is_self(): void
    {
        $user = User::factory()->create();
        $status = $this->makeStatus();
        Issue::factory()->for($status, 'status')->create();

        $this->actingAs($user)
            ->postJson("/api/statuses/{$status->id}/migrate-and-delete", [
                'target_status_id' => $status->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_status_id']);
    }

    // -------------------------------------------------------------------------
    // Happy path — migrate issues to target status
    // -------------------------------------------------------------------------

    /** Issues are bulk-updated to target_status_id and the original status is deleted. */
    public function test_migrates_issues_to_target_status_and_deletes_original(): void
    {
        $user = User::factory()->create();
        $target = IssueStatus::where('slug', 'open')->first();
        $status = $this->makeStatus();
        $issues = Issue::factory()->for($status, 'status')->count(3)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/statuses/{$status->id}/migrate-and-delete", [
                'target_status_id' => $target->id,
            ]);

        $response->assertStatus(204);

        // Original status deleted
        $this->assertDatabaseMissing('statuses', ['id' => $status->id]);

        // All issues now point at the target status
        foreach ($issues as $issue) {
            $this->assertDatabaseHas('issues', [
                'id' => $issue->id,
                'status_id' => $target->id,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Happy path — delete all issues
    // -------------------------------------------------------------------------

    /** Issues are deleted and the status is removed when delete_issues=true. */
    public function test_deletes_issues_and_status_when_delete_issues_true(): void
    {
        $user = User::factory()->create();
        $status = $this->makeStatus();
        $issues = Issue::factory()->for($status, 'status')->count(2)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/statuses/{$status->id}/migrate-and-delete", [
                'delete_issues' => true,
            ]);

        $response->assertStatus(204);

        // Original status deleted
        $this->assertDatabaseMissing('statuses', ['id' => $status->id]);

        // All issues deleted
        foreach ($issues as $issue) {
            $this->assertDatabaseMissing('issues', ['id' => $issue->id]);
        }
    }

    // -------------------------------------------------------------------------
    // Happy path — zero-issue status
    // -------------------------------------------------------------------------

    /** A status with zero issues is deleted without needing target or delete_issues. */
    public function test_deletes_status_with_zero_issues_without_options(): void
    {
        $user = User::factory()->create();
        $status = $this->makeStatus();

        $this->actingAs($user)
            ->postJson("/api/statuses/{$status->id}/migrate-and-delete", [])
            ->assertStatus(204);

        $this->assertDatabaseMissing('statuses', ['id' => $status->id]);
    }
}
