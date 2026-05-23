<?php

namespace Tests\Feature;

use App\Http\Requests\ToggleCommentReactionRequest;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Emoji Reactions on Comments — API feature tests.
 *
 * Covers:
 *  POST /api/comments/{comment}/reactions — toggle on, toggle off, invalid emoji, 401
 *  GET  /api/comments/{comment}/reactions — grouped counts + reacted flag
 *  Cascade delete: deleting comment removes its reactions
 *  Multi-user reaction counts
 *
 * @see Task 07.04
 */
class CommentReactionApiTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Creates an issue + comment owned by a new user. Returns [user, issue, comment]. */
    private function makeCommentFixture(): array
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        $comment = Comment::factory()->for($issue)->for($user)->create();

        return [$user, $issue, $comment];
    }

    // =========================================================================
    // Toggle ON — reaction is added
    // =========================================================================

    /** POST with valid emoji creates the reaction and returns "added". */
    public function test_toggle_creates_reaction_and_returns_added(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();

        $response = $this->actingAs($user)
            ->postJson("/api/comments/{$comment->id}/reactions", ['emoji' => '👍']);

        $response->assertStatus(200)
            ->assertJsonPath('toggled', 'added');

        $this->assertDatabaseHas('comment_reactions', [
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'emoji' => '👍',
        ]);
    }

    /** Response after toggle-on includes updated reaction counts. */
    public function test_toggle_on_response_includes_reaction_counts(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();

        $response = $this->actingAs($user)
            ->postJson("/api/comments/{$comment->id}/reactions", ['emoji' => '👍']);

        $response->assertStatus(200)
            ->assertJsonPath('reactions.👍.count', 1)
            ->assertJsonPath('reactions.👍.reacted', true);
    }

    // =========================================================================
    // Toggle OFF — reaction is removed
    // =========================================================================

    /** POST when reaction already exists removes it and returns "removed". */
    public function test_toggle_removes_existing_reaction_and_returns_removed(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();

        // Create the reaction first
        CommentReaction::create([
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'emoji' => '❤️',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/comments/{$comment->id}/reactions", ['emoji' => '❤️']);

        $response->assertStatus(200)
            ->assertJsonPath('toggled', 'removed');

        $this->assertDatabaseMissing('comment_reactions', [
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'emoji' => '❤️',
        ]);
    }

    /** Response after toggle-off has zero count for the removed emoji. */
    public function test_toggle_off_response_shows_zero_for_removed_emoji(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();

        CommentReaction::create([
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'emoji' => '🎉',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/comments/{$comment->id}/reactions", ['emoji' => '🎉']);

        $response->assertStatus(200)
            ->assertJsonPath('toggled', 'removed');

        // The emoji key should be absent from the summary (no reactions left)
        $reactions = $response->json('reactions');
        $this->assertArrayNotHasKey('🎉', $reactions);
    }

    // =========================================================================
    // Validation — invalid emoji
    // =========================================================================

    /** Invalid emoji returns 422 with validation error. */
    public function test_toggle_with_invalid_emoji_returns_422(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();

        $this->actingAs($user)
            ->postJson("/api/comments/{$comment->id}/reactions", ['emoji' => '🤖'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['emoji']);
    }

    /** Missing emoji field returns 422. */
    public function test_toggle_without_emoji_returns_422(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();

        $this->actingAs($user)
            ->postJson("/api/comments/{$comment->id}/reactions", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['emoji']);
    }

    /** All 8 allowed emojis pass validation. */
    public function test_all_allowed_emojis_are_accepted(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();

        foreach (ToggleCommentReactionRequest::ALLOWED_EMOJIS as $emoji) {
            $response = $this->actingAs($user)
                ->postJson("/api/comments/{$comment->id}/reactions", ['emoji' => $emoji]);

            $response->assertStatus(200);

            // Toggle back off so we can use the same comment cleanly
            $this->actingAs($user)
                ->postJson("/api/comments/{$comment->id}/reactions", ['emoji' => $emoji]);
        }
    }

    // =========================================================================
    // Authentication — 401
    // =========================================================================

    /** Unauthenticated toggle returns 401. */
    public function test_unauthenticated_toggle_returns_401(): void
    {
        [, , $comment] = $this->makeCommentFixture();

        $this->postJson("/api/comments/{$comment->id}/reactions", ['emoji' => '👍'])
            ->assertStatus(401);
    }

    /** Unauthenticated list returns 401. */
    public function test_unauthenticated_list_returns_401(): void
    {
        [, , $comment] = $this->makeCommentFixture();

        $this->getJson("/api/comments/{$comment->id}/reactions")
            ->assertStatus(401);
    }

    // =========================================================================
    // GET /api/comments/{comment}/reactions — grouped list
    // =========================================================================

    /** List returns correct grouped structure with user names. */
    public function test_list_returns_grouped_reactions_with_user_names(): void
    {
        [$owner, , $comment] = $this->makeCommentFixture();
        $otherUser = User::factory()->create(['name' => 'Bob']);

        CommentReaction::create(['comment_id' => $comment->id, 'user_id' => $owner->id, 'emoji' => '👍']);
        CommentReaction::create(['comment_id' => $comment->id, 'user_id' => $otherUser->id, 'emoji' => '👍']);
        CommentReaction::create(['comment_id' => $comment->id, 'user_id' => $owner->id, 'emoji' => '❤️']);

        $response = $this->actingAs($owner)
            ->getJson("/api/comments/{$comment->id}/reactions");

        $response->assertStatus(200)
            ->assertJsonPath('data.👍.count', 2)
            ->assertJsonPath('data.❤️.count', 1);

        // Bob's name appears in the 👍 users list
        $thumbsUsers = $response->json('data.👍.users');
        $this->assertContains('Bob', $thumbsUsers);
    }

    /** reacted flag is true when the auth user has reacted, false otherwise. */
    public function test_list_reacted_flag_is_correct(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();
        $other = User::factory()->create();

        // user reacted with 👍, other reacted with 😄
        CommentReaction::create(['comment_id' => $comment->id, 'user_id' => $user->id, 'emoji' => '👍']);
        CommentReaction::create(['comment_id' => $comment->id, 'user_id' => $other->id, 'emoji' => '😄']);

        $response = $this->actingAs($user)
            ->getJson("/api/comments/{$comment->id}/reactions");

        $response->assertStatus(200)
            ->assertJsonPath('data.👍.reacted', true)   // user reacted with 👍
            ->assertJsonPath('data.😄.reacted', false);  // only other reacted with 😄
    }

    /** Empty reactions returns empty data object. */
    public function test_list_with_no_reactions_returns_empty_data(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();

        $response = $this->actingAs($user)
            ->getJson("/api/comments/{$comment->id}/reactions");

        $response->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    // =========================================================================
    // Multi-user reaction counts
    // =========================================================================

    /** Three users react with the same emoji — count is 3. */
    public function test_three_users_reacting_same_emoji_gives_count_3(): void
    {
        [, , $comment] = $this->makeCommentFixture();
        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            CommentReaction::create([
                'comment_id' => $comment->id,
                'user_id' => $user->id,
                'emoji' => '🚀',
            ]);
        }

        $response = $this->actingAs($users->first())
            ->getJson("/api/comments/{$comment->id}/reactions");

        $response->assertStatus(200)
            ->assertJsonPath('data.🚀.count', 3);
    }

    /** Same user cannot react twice with the same emoji — DB unique constraint. */
    public function test_same_user_cannot_react_twice_with_same_emoji(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();

        // First toggle — adds
        $this->actingAs($user)
            ->postJson("/api/comments/{$comment->id}/reactions", ['emoji' => '👀'])
            ->assertJsonPath('toggled', 'added');

        // Second toggle — removes (toggle semantics)
        $this->actingAs($user)
            ->postJson("/api/comments/{$comment->id}/reactions", ['emoji' => '👀'])
            ->assertJsonPath('toggled', 'removed');

        $this->assertDatabaseMissing('comment_reactions', [
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'emoji' => '👀',
        ]);
    }

    // =========================================================================
    // Cascade delete
    // =========================================================================

    /** Deleting a comment removes all its reactions (cascadeOnDelete). */
    public function test_deleting_comment_cascades_to_reactions(): void
    {
        [$user, , $comment] = $this->makeCommentFixture();

        CommentReaction::create(['comment_id' => $comment->id, 'user_id' => $user->id, 'emoji' => '👍']);
        CommentReaction::create(['comment_id' => $comment->id, 'user_id' => $user->id, 'emoji' => '❤️']);

        $this->assertDatabaseCount('comment_reactions', 2);

        $comment->delete();

        $this->assertDatabaseCount('comment_reactions', 0);
    }

    // =========================================================================
    // reactions_summary in IssueResource comment responses
    // =========================================================================

    /** GET /api/issues/{issue} includes reactions_summary for each comment. */
    public function test_issue_show_includes_reactions_summary_on_comments(): void
    {
        [$user, $issue, $comment] = $this->makeCommentFixture();

        CommentReaction::create(['comment_id' => $comment->id, 'user_id' => $user->id, 'emoji' => '👍']);

        $response = $this->actingAs($user)
            ->getJson("/api/issues/{$issue->id}");

        $response->assertStatus(200);

        $comments = $response->json('data.comments');
        $this->assertNotEmpty($comments);

        $firstComment = $comments[0];
        $this->assertArrayHasKey('reactions_summary', $firstComment);
        $this->assertArrayHasKey('👍', $firstComment['reactions_summary']);
        $this->assertEquals(1, $firstComment['reactions_summary']['👍']['count']);
        $this->assertTrue($firstComment['reactions_summary']['👍']['reacted']);
    }
}
