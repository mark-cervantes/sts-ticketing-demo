<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Comments API — Feature & Integration Tests (HTTP layer).
 *
 * SRS §FR-07 / SRS §8.2 I-03 / ADR-004 permission ladder.
 *
 * Covers POST /api/issues/{issue}/comments:
 *  - Valid comment creation (201 + correct response shape)
 *  - Body validation: empty, spaces-only (422)
 *  - user_id set from auth, not request input
 *  - Nonexistent issue (404)
 *  - Authorization ladder: owner, shared(comment), shared(edit) → 201
 *  - Authorization ladder: private (no share), public (no share), shared(view) → 403
 *  - Response includes user.name for display
 *
 * Integration (SRS §8.2 I-03):
 *  - 3 comments created, then GET show: assert bounded query count (N+1 guard)
 */
class CommentApiTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // POST /api/issues/{issue}/comments — Valid creation
    // =========================================================================

    /** SRS §FR-07: authenticated owner can add a comment to their issue. */
    public function test_owner_can_add_comment_to_their_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Reproduced on Firefox 124 with the default theme enabled.',
        ]);

        $response->assertStatus(201);
    }

    /** SRS §FR-07: successful comment response includes id, body, created_at, user. */
    public function test_create_comment_response_has_correct_shape(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Initial triage: issue confirms on production and staging.',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'body',
                    'created_at',
                    'user' => ['id', 'name'],
                ],
            ]);
    }

    /** SRS §FR-07: comment response includes correct user.name for display. */
    public function test_create_comment_response_includes_user_name(): void
    {
        $user = User::factory()->create(['name' => 'Grace Hopper']);
        $issue = Issue::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Linked to upstream dependency — see JIRA-1042.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.user.name', 'Grace Hopper');
    }

    /** SRS §FR-07: comment body is stored correctly. */
    public function test_create_comment_stores_correct_body(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        $body = 'Stack trace attached: TypeError at line 42 in PaymentProcessor.php';

        $response = $this->actingAs($user)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => $body,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.body', $body);
    }

    /** SRS §FR-07: comment is persisted to the database. */
    public function test_create_comment_is_saved_to_database(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Confirmed: memory leak in the reporting module after 1000 iterations.',
        ])->assertStatus(201);

        $this->assertDatabaseHas('comments', [
            'issue_id' => $issue->id,
            'user_id' => $user->id,
        ]);
    }

    // =========================================================================
    // Validation — empty and whitespace body
    // =========================================================================

    /** Validation: empty body is rejected with 422. */
    public function test_create_comment_fails_when_body_is_empty(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => '',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    /** Validation: body of only spaces is rejected with 422 (trim + min:1 guard). */
    public function test_create_comment_fails_when_body_is_spaces_only(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => '     ',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    /** Validation: missing body field is rejected with 422. */
    public function test_create_comment_fails_when_body_is_missing(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/issues/{$issue->id}/comments", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    // =========================================================================
    // user_id from auth — never from request input
    // =========================================================================

    /** SRS §FR-07: user_id is set from authenticated user, not from request body. */
    public function test_create_comment_sets_user_id_from_auth_not_request(): void
    {
        $authUser = User::factory()->create();
        $otherUser = User::factory()->create();
        $issue = Issue::factory()->for($authUser)->create();

        $this->actingAs($authUser)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Auth check: user_id must come from the session.',
            'user_id' => $otherUser->id,  // malicious input — must be ignored
        ])->assertStatus(201);

        // Stored comment must belong to the authenticated user
        $this->assertDatabaseHas('comments', [
            'issue_id' => $issue->id,
            'user_id' => $authUser->id,
        ]);

        // And must NOT be attributed to the injected ID
        $this->assertDatabaseMissing('comments', [
            'issue_id' => $issue->id,
            'user_id' => $otherUser->id,
        ]);
    }

    // =========================================================================
    // Nonexistent / soft-deleted issue
    // =========================================================================

    /** SRS §FR-07: comment on a nonexistent issue returns 404. */
    public function test_create_comment_returns_404_for_nonexistent_issue(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/issues/99999/comments', [
            'body' => 'This issue does not exist.',
        ])->assertStatus(404);
    }

    /** SRS §FR-07: comment on a soft-deleted issue returns 404 (route-model binding excludes soft-deletes). */
    public function test_create_comment_returns_404_for_soft_deleted_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        $issue->delete();

        $this->actingAs($user)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Trying to comment on a deleted issue.',
        ])->assertStatus(404);
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    /** SRS §8.2: unauthenticated request to create comment returns 401. */
    public function test_unauthenticated_user_cannot_create_comment(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Unauthenticated attempt.',
        ])->assertStatus(401);
    }

    // =========================================================================
    // Authorization — ADR-004 permission ladder
    // =========================================================================

    /** ADR-004: unrelated user cannot comment on another user's private issue (403). */
    public function test_unrelated_user_cannot_comment_on_private_issue(): void
    {
        $owner = User::factory()->create();
        $unrelated = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $this->actingAs($unrelated)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Unauthorized access attempt on private issue.',
        ])->assertStatus(403);
    }

    /** ADR-004: unrelated user cannot comment on a public issue without a share (view-only public ≠ comment). */
    public function test_unrelated_user_cannot_comment_on_public_issue_without_share(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $issue = Issue::factory()->for($owner)->public()->create();

        $this->actingAs($reader)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Attempting to comment on a public issue without a share.',
        ])->assertStatus(403);
    }

    /** ADR-004: shared(view) user cannot comment — view permission is below comment on the ladder. */
    public function test_view_shared_user_cannot_create_comment(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($viewer, 'user')->view()->create();

        $this->actingAs($viewer)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'View-only users should not be able to comment.',
        ])->assertStatus(403);
    }

    /** ADR-004: shared(comment) user can create a comment (201). */
    public function test_comment_shared_user_can_create_comment(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($commenter, 'user')->comment()->create();

        $this->actingAs($commenter)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Comment-permission user adds a comment successfully.',
        ])->assertStatus(201);
    }

    /** ADR-004: shared(edit) user can create a comment — edit implies comment on the ladder (201). */
    public function test_edit_shared_user_can_create_comment(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($editor, 'user')->edit()->create();

        $this->actingAs($editor)->postJson("/api/issues/{$issue->id}/comments", [
            'body' => 'Edit-permission user also has comment access per ADR-004 ladder.',
        ])->assertStatus(201);
    }

    // =========================================================================
    // SRS §8.2 I-03 — Integration: create 3 comments → GET show → N+1 guard
    // =========================================================================

    /**
     * SRS §8.2 I-03: after 3 comments are added, GET show returns all three
     * with user.name, and the total query count is bounded (no N+1).
     *
     * Expected query budget for show with 3 comments:
     *   auth + issues + category (eager) + user (eager) + comments.user (eager) + shares
     *   → well under 15 queries regardless of comment count.
     */
    public function test_show_after_3_comments_returns_all_with_user_name_and_no_n_plus_1(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        // Create 3 commenters and post a comment each via the API
        $commenters = User::factory()->count(3)->create();
        foreach ($commenters as $commenter) {
            IssueShare::factory()->for($issue)->for($commenter, 'user')->comment()->create();
            $this->actingAs($commenter)->postJson("/api/issues/{$issue->id}/comments", [
                'body' => "Comment by {$commenter->name}: investigation note added.",
            ])->assertStatus(201);
        }

        // Now hit the show endpoint and assert the response shape and query budget
        DB::enableQueryLog();

        $response = $this->actingAs($owner)->getJson("/api/issues/{$issue->id}");

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200);

        // Assert all 3 comments are present
        $comments = $response->json('data.comments');
        $this->assertCount(3, $comments, 'Show endpoint should return exactly 3 comments.');

        // Assert every comment has user.name (no null user relation)
        foreach ($comments as $comment) {
            $this->assertArrayHasKey('user', $comment);
            $this->assertArrayHasKey('name', $comment['user']);
            $this->assertNotEmpty($comment['user']['name']);
        }

        // Assert bounded query count — must not grow with comment count (N+1 guard)
        $this->assertLessThan(
            15,
            $queryCount,
            "Expected fewer than 15 queries for show with 3 comments, got {$queryCount}. Possible N+1 on comments.user."
        );
    }
}
