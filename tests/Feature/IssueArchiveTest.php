<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the ticket archiving feature.
 *
 * Covers:
 *  - PATCH /api/issues/{issue}/archive
 *  - PATCH /api/issues/{issue}/unarchive
 *  - POST  /api/issues/bulk-archive
 *  - GET   /api/issues — default excludes archived; ?include_archived=1 includes them
 *  - Authorization: 403 for non-owners without edit share
 *
 * @see vault/SPEC §4.2 / Task 10.04
 */
class IssueArchiveTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // PATCH /api/issues/{issue}/archive
    // =========================================================================

    /** Archive: resolved issue → archived_at set, returns 200. */
    public function test_owner_can_archive_a_resolved_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->resolved()->create();

        $response = $this->actingAs($user)->patchJson("/api/issues/{$issue->id}/archive");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $issue->id);

        $this->assertNotNull($response->json('data.archived_at'));
        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'archived_at' => now()->format('Y-m-d H:i:s')]);
    }

    /** Archive: response includes archived_at as ISO 8601 string. */
    public function test_archive_response_includes_archived_at_field(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->resolved()->create();

        $response = $this->actingAs($user)->patchJson("/api/issues/{$issue->id}/archive");

        $response->assertStatus(200);
        $archivedAt = $response->json('data.archived_at');
        $this->assertNotNull($archivedAt);
        // Should be parseable as ISO 8601
        $this->assertNotFalse(strtotime($archivedAt));
    }

    /** Archive: non-resolved issue → 422. */
    public function test_archiving_non_resolved_issue_returns_422(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->open()->create();

        $response = $this->actingAs($user)->patchJson("/api/issues/{$issue->id}/archive");

        $response->assertStatus(422);
        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'archived_at' => null]);
    }

    /** Archive: in-progress issue → 422. */
    public function test_archiving_in_progress_issue_returns_422(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->inProgress()->create();

        $response = $this->actingAs($user)->patchJson("/api/issues/{$issue->id}/archive");

        $response->assertStatus(422);
    }

    /** Archive: non-owner without share gets 403. */
    public function test_non_owner_cannot_archive_issue(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->resolved()->create();

        $this->actingAs($other)->patchJson("/api/issues/{$issue->id}/archive")
            ->assertStatus(403);
    }

    /** Archive: user with view-share cannot archive (403). */
    public function test_user_with_view_share_cannot_archive_issue(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->resolved()->create();
        IssueShare::factory()->for($issue)->for($viewer, 'user')->view()->create();

        $this->actingAs($viewer)->patchJson("/api/issues/{$issue->id}/archive")
            ->assertStatus(403);
    }

    /** Archive: user with edit-share can archive a resolved issue. */
    public function test_user_with_edit_share_can_archive_resolved_issue(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $issue = Issue::factory()->for($owner)->resolved()->create();
        IssueShare::factory()->for($issue)->for($editor, 'user')->edit()->create();

        $this->actingAs($editor)->patchJson("/api/issues/{$issue->id}/archive")
            ->assertStatus(200);
    }

    /** Archive: unauthenticated request returns 401. */
    public function test_unauthenticated_user_cannot_archive_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->resolved()->create();

        $this->patchJson("/api/issues/{$issue->id}/archive")->assertStatus(401);
    }

    // =========================================================================
    // PATCH /api/issues/{issue}/unarchive
    // =========================================================================

    /** Unarchive: archived issue → archived_at null, returns 200. */
    public function test_owner_can_unarchive_an_archived_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->resolved()->create(['archived_at' => now()->subDay()]);

        $response = $this->actingAs($user)->patchJson("/api/issues/{$issue->id}/unarchive");

        $response->assertStatus(200)
            ->assertJsonPath('data.archived_at', null);

        $this->assertDatabaseHas('issues', ['id' => $issue->id, 'archived_at' => null]);
    }

    /** Unarchive: non-owner without share gets 403. */
    public function test_non_owner_cannot_unarchive_issue(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->resolved()->create(['archived_at' => now()->subDay()]);

        $this->actingAs($other)->patchJson("/api/issues/{$issue->id}/unarchive")
            ->assertStatus(403);
    }

    /** Unarchive: unauthenticated returns 401. */
    public function test_unauthenticated_user_cannot_unarchive_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->resolved()->create(['archived_at' => now()->subDay()]);

        $this->patchJson("/api/issues/{$issue->id}/unarchive")->assertStatus(401);
    }

    // =========================================================================
    // POST /api/issues/bulk-archive
    // =========================================================================

    /** Bulk archive: archives only resolved+active issues owned by the user. */
    public function test_bulk_archive_archives_resolved_active_issues(): void
    {
        $user = User::factory()->create();
        $resolvedIssue1 = Issue::factory()->for($user)->resolved()->create();
        $resolvedIssue2 = Issue::factory()->for($user)->resolved()->create();
        $openIssue = Issue::factory()->for($user)->open()->create();
        $inProgressIssue = Issue::factory()->for($user)->inProgress()->create();

        $response = $this->actingAs($user)->postJson('/api/issues/bulk-archive');

        $response->assertStatus(200)
            ->assertJsonPath('archived_count', 2);

        $this->assertNotNull(Issue::find($resolvedIssue1->id)->archived_at);
        $this->assertNotNull(Issue::find($resolvedIssue2->id)->archived_at);
        $this->assertNull(Issue::find($openIssue->id)->archived_at);
        $this->assertNull(Issue::find($inProgressIssue->id)->archived_at);
    }

    /** Bulk archive: already-archived resolved issues are NOT double-archived. */
    public function test_bulk_archive_skips_already_archived_issues(): void
    {
        $user = User::factory()->create();
        $alreadyArchived = Issue::factory()->for($user)->resolved()->create(['archived_at' => now()->subDay()]);
        $activeResolved = Issue::factory()->for($user)->resolved()->create();

        $response = $this->actingAs($user)->postJson('/api/issues/bulk-archive');

        $response->assertStatus(200)
            ->assertJsonPath('archived_count', 1);

        // Already-archived issue timestamp unchanged (scopeActive excludes it)
        $this->assertEquals(
            $alreadyArchived->archived_at->toDateTimeString(),
            Issue::find($alreadyArchived->id)->archived_at->toDateTimeString(),
        );
    }

    /** Bulk archive: returns 0 when no resolved issues exist. */
    public function test_bulk_archive_returns_zero_when_nothing_to_archive(): void
    {
        $user = User::factory()->create();
        Issue::factory()->for($user)->open()->create();

        $this->actingAs($user)->postJson('/api/issues/bulk-archive')
            ->assertStatus(200)
            ->assertJsonPath('archived_count', 0);
    }

    /** Bulk archive: does not archive other users' private issues. */
    public function test_bulk_archive_only_affects_accessible_issues(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherIssue = Issue::factory()->for($otherUser)->resolved()->private()->create();

        $this->actingAs($user)->postJson('/api/issues/bulk-archive')
            ->assertStatus(200)
            ->assertJsonPath('archived_count', 0);

        $this->assertNull(Issue::find($otherIssue->id)->archived_at);
    }

    /** Bulk archive: unauthenticated returns 401. */
    public function test_unauthenticated_user_cannot_bulk_archive(): void
    {
        $this->postJson('/api/issues/bulk-archive')->assertStatus(401);
    }

    // =========================================================================
    // GET /api/issues — archived issue visibility
    // =========================================================================

    /** Kanban index: default excludes archived issues. */
    public function test_index_excludes_archived_issues_by_default(): void
    {
        $user = User::factory()->create();
        $activeIssue = Issue::factory()->for($user)->create();
        $archivedIssue = Issue::factory()->for($user)->resolved()->create(['archived_at' => now()->subDay()]);

        $response = $this->actingAs($user)->getJson('/api/issues');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($activeIssue->id));
        $this->assertFalse($ids->contains($archivedIssue->id));
    }

    /** Kanban index: ?include_archived=1 includes archived issues. */
    public function test_index_includes_archived_issues_with_include_archived_param(): void
    {
        $user = User::factory()->create();
        $activeIssue = Issue::factory()->for($user)->create();
        $archivedIssue = Issue::factory()->for($user)->resolved()->create(['archived_at' => now()->subDay()]);

        $response = $this->actingAs($user)->getJson('/api/issues?include_archived=1');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($activeIssue->id));
        $this->assertTrue($ids->contains($archivedIssue->id));
    }

    /** Kanban index: total count reflects only non-archived issues by default. */
    public function test_index_total_excludes_archived_by_default(): void
    {
        $user = User::factory()->create();
        Issue::factory()->for($user)->create();
        Issue::factory()->for($user)->create();
        Issue::factory()->for($user)->resolved()->create(['archived_at' => now()->subDay()]);

        $response = $this->actingAs($user)->getJson('/api/issues');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 2);
    }

    /** IssueResource: archived_at is present in single issue response. */
    public function test_show_response_includes_archived_at_field(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->resolved()->create(['archived_at' => now()->subDay()]);

        $response = $this->actingAs($user)->getJson("/api/issues/{$issue->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['archived_at']]);
    }

    /** IssueResource: can.archive gate is present in the response. */
    public function test_issue_resource_includes_can_archive_gate(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson("/api/issues/{$issue->id}");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['can' => ['archive']]]);
    }
}
