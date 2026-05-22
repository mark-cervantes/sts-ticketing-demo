<?php

namespace Tests\Feature\Policies;

use App\Models\Comment;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SRS §8.2 — CommentPolicy authorization contract.
 *
 * CommentPolicy::create delegates entirely to IssuePolicy::comment.
 * These tests verify the delegation by testing the full permission matrix
 * through $user->can('create', [Comment::class, $issue]).
 *
 * Coder must implement app/Policies/CommentPolicy.php to make these pass.
 */
class CommentPolicyTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Legacy IssuePolicy::comment shorthand (retained for regression coverage)
    // -------------------------------------------------------------------------

    /** SRS §8.2: issue owner can create a comment on their own issue. */
    public function test_owner_can_create_comment_on_own_issue(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $this->assertTrue($owner->can('comment', $issue));
    }

    /** SRS §8.2: view-shared user cannot create a comment (ladder: view < comment). */
    public function test_view_shared_user_cannot_create_comment(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($viewer, 'user')->view()->create();

        $this->assertFalse($viewer->can('comment', $issue));
    }

    /** SRS §8.2: comment-shared user can create a comment. */
    public function test_comment_shared_user_can_create_comment(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($commenter, 'user')->comment()->create();

        $this->assertTrue($commenter->can('comment', $issue));
    }

    /** SRS §8.2: edit-shared user can create a comment (ladder: edit ≥ comment). */
    public function test_edit_shared_user_can_create_comment(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($editor, 'user')->edit()->create();

        $this->assertTrue($editor->can('comment', $issue));
    }

    /** SRS §8.2 I-06: unrelated user cannot comment on a private issue. */
    public function test_unrelated_authenticated_user_cannot_comment_on_private_issue(): void
    {
        $owner = User::factory()->create();
        $unrelated = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $this->assertFalse($unrelated->can('comment', $issue));
    }

    /** SRS §8.2 SPEC §3.2: public visibility does NOT grant comment access without a share. */
    public function test_unrelated_authenticated_user_cannot_comment_on_public_issue_without_share(): void
    {
        $owner = User::factory()->create();
        $unrelated = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->assertFalse($unrelated->can('comment', $issue));
    }

    // -------------------------------------------------------------------------
    // CommentPolicy::create — array-syntax invocation route
    //
    // $user->can('create', [Comment::class, $issue]) routes through
    // CommentPolicy::create(User, Issue), which delegates to IssuePolicy::comment.
    // These tests verify the full permission matrix through that actual path.
    // -------------------------------------------------------------------------

    /** SRS §8.2: owner can create comment via CommentPolicy array-syntax. */
    public function test_owner_can_create_comment_via_comment_policy_array_syntax(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $this->assertTrue($owner->can('create', [Comment::class, $issue]));
    }

    /** SRS §8.2: view-shared user cannot create comment via CommentPolicy array-syntax (ladder: view < comment). */
    public function test_view_shared_user_cannot_create_comment_via_comment_policy(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($viewer, 'user')->view()->create();

        $this->assertFalse($viewer->can('create', [Comment::class, $issue]));
    }

    /** SRS §8.2: comment-shared user can create comment via CommentPolicy array-syntax. */
    public function test_comment_shared_user_can_create_comment_via_comment_policy(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($commenter, 'user')->comment()->create();

        $this->assertTrue($commenter->can('create', [Comment::class, $issue]));
    }

    /** SRS §8.2: edit-shared user can create comment via CommentPolicy array-syntax (ladder: edit ≥ comment). */
    public function test_edit_shared_user_can_create_comment_via_comment_policy(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($editor, 'user')->edit()->create();

        $this->assertTrue($editor->can('create', [Comment::class, $issue]));
    }

    /** SRS §8.2 I-06: unrelated user cannot create comment via CommentPolicy array-syntax (private issue). */
    public function test_unrelated_user_cannot_create_comment_via_comment_policy_on_private_issue(): void
    {
        $owner = User::factory()->create();
        $unrelated = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $this->assertFalse($unrelated->can('create', [Comment::class, $issue]));
    }

    /** SRS §8.2 SPEC §3.2: public visibility does NOT grant CommentPolicy create access without a share. */
    public function test_unrelated_user_cannot_create_comment_via_comment_policy_on_public_issue_without_share(): void
    {
        $owner = User::factory()->create();
        $unrelated = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->assertFalse($unrelated->can('create', [Comment::class, $issue]));
    }
}
