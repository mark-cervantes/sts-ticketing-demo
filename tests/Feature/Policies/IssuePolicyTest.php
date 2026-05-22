<?php

namespace Tests\Feature\Policies;

use App\Enums\Permission;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SRS §8.2 — IssuePolicy authorization contract.
 *
 * Tests call $user->can('ability', $issue) directly — no HTTP routes needed.
 * Coder must implement app/Policies/IssuePolicy.php to make these pass.
 */
class IssuePolicyTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // viewAny — any authenticated user
    // -------------------------------------------------------------------------

    /** SRS §8.2 I-18: any authenticated user may reach the list endpoint. */
    public function test_view_any_allowed_for_any_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->can('viewAny', Issue::class));
    }

    // -------------------------------------------------------------------------
    // create — any authenticated user
    // -------------------------------------------------------------------------

    /** SRS §8.2: any authenticated user can create an issue. */
    public function test_create_allowed_for_any_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->can('create', Issue::class));
    }

    // -------------------------------------------------------------------------
    // view
    // -------------------------------------------------------------------------

    /** SRS §8.2: owner can view their own issue. */
    public function test_owner_can_view_own_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->private()->create();

        $this->assertTrue($user->can('view', $issue));
    }

    /** SRS §8.2 I-06: a private issue is not viewable without a share. */
    public function test_private_issue_unviewable_without_share(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $this->assertFalse($other->can('view', $issue));
    }

    /** SRS §8.2: any authenticated user can view a public issue. */
    public function test_public_issue_viewable_by_any_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->assertTrue($other->can('view', $issue));
    }

    /** SRS §8.2: a view-share grants view access. */
    public function test_non_owner_with_view_share_can_view_but_not_update(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($viewer, 'user')->view()->create();

        $this->assertTrue($viewer->can('view', $issue));
        $this->assertFalse($viewer->can('update', $issue));
    }

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    /** SRS §8.2: owner can update their own issue. */
    public function test_owner_can_update_own_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->assertTrue($user->can('update', $issue));
    }

    /** SRS §8.2: a public issue is not updatable without an edit-share. */
    public function test_public_issue_not_updatable_without_edit_share(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->assertFalse($other->can('update', $issue));
    }

    /** SRS §8.2: edit-share grants update ability. */
    public function test_non_owner_with_edit_share_can_view_comment_and_update(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($editor, 'user')->edit()->create();

        $this->assertTrue($editor->can('view', $issue));
        $this->assertTrue($editor->can('comment', $issue));
        $this->assertTrue($editor->can('update', $issue));
    }

    // -------------------------------------------------------------------------
    // comment (non-standard policy ability)
    // -------------------------------------------------------------------------

    /** SRS §8.2: view-share does NOT grant comment ability (ladder). */
    public function test_non_owner_with_view_share_cannot_comment(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($viewer, 'user')->view()->create();

        $this->assertFalse($viewer->can('comment', $issue));
    }

    /** SRS §8.2: comment-share grants comment but NOT update (ladder). */
    public function test_non_owner_with_comment_share_can_view_and_comment_but_not_update(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($commenter, 'user')->comment()->create();

        $this->assertTrue($commenter->can('view', $issue));
        $this->assertTrue($commenter->can('comment', $issue));
        $this->assertFalse($commenter->can('update', $issue));
    }

    /** SRS §8.2 SPEC §3.2: public issue is view-only — no commenting without a share. */
    public function test_public_issue_not_commentable_without_share(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->assertFalse($other->can('comment', $issue));
    }

    // -------------------------------------------------------------------------
    // delete — owner only
    // -------------------------------------------------------------------------

    /** SRS §8.2: owner can delete their own issue. */
    public function test_owner_can_delete_own_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->assertTrue($user->can('delete', $issue));
    }

    /** SRS §8.2: no share level grants delete ability. */
    public function test_non_owner_with_any_share_cannot_delete(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        // Use the highest permission — edit — to confirm delete is still denied.
        IssueShare::factory()->for($issue)->for($editor, 'user')->edit()->create();

        $this->assertFalse($editor->can('delete', $issue));
    }

    // -------------------------------------------------------------------------
    // share — owner only
    // -------------------------------------------------------------------------

    /** SRS §8.2: owner can share their own issue. */
    public function test_owner_can_share_own_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->assertTrue($user->can('share', $issue));
    }

    /** SRS §8.2: no share level grants further sharing ability. */
    public function test_non_owner_with_any_share_cannot_share_further(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($editor, 'user')->edit()->create();

        $this->assertFalse($editor->can('share', $issue));
    }

    // -------------------------------------------------------------------------
    // restore — owner only (soft-deleted issues)
    // -------------------------------------------------------------------------

    /** SRS §8.2: owner can restore a soft-deleted issue. */
    public function test_restore_owner_only(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        // Soft-delete so the restore ability is meaningful.
        $issue->delete();
        $issue = Issue::withTrashed()->find($issue->id);

        $this->assertTrue($owner->can('restore', $issue));
        $this->assertFalse($other->can('restore', $issue));
    }

    // -------------------------------------------------------------------------
    // forceDelete — owner only
    // -------------------------------------------------------------------------

    /** SRS §8.2: owner can permanently delete their own issue. */
    public function test_owner_can_force_delete_own_issue(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        $this->assertTrue($owner->can('forceDelete', $issue));
    }

    /** SRS §8.2: non-owner cannot permanently delete an issue, even with the highest share level. */
    public function test_non_owner_cannot_force_delete_issue(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        // Grant the highest share level (edit) — must still be denied.
        IssueShare::factory()->for($issue)->for($editor, 'user')->edit()->create();

        $this->assertFalse($editor->can('forceDelete', $issue));
    }

    /** SRS §8.2: unrelated authenticated user cannot permanently delete an issue. */
    public function test_unrelated_user_cannot_force_delete_issue(): void
    {
        $owner = User::factory()->create();
        $unrelated = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        $this->assertFalse($unrelated->can('forceDelete', $issue));
    }
}
