<?php

namespace Tests\Feature;

use App\Enums\Priority;
use App\Enums\Status;
use App\Enums\Visibility;
use App\Jobs\GenerateSummaryJob;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Issue CRUD API — Feature Tests (HTTP layer).
 *
 * SRS §8.2 I-01, I-02, I-04, I-05, I-12, I-13, I-16, I-17 (and validation/auth cases)
 *
 * Covers:
 *  - POST /api/issues   (create)
 *  - GET  /api/issues   (list with filters + pagination)
 *  - GET  /api/issues/{id}  (show with comments)
 *  - PATCH /api/issues/{id} (update with optimistic locking)
 *  - DELETE /api/issues/{id} (soft delete)
 *  - StoreIssueRequest validation
 *  - UpdateIssueRequest validation
 *  - IssuePolicy enforcement via HTTP
 */
class IssueCrudApiTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // POST /api/issues — Create
    // =========================================================================

    /** SRS §8.2 I-01: authenticated user can create an issue with required fields. */
    public function test_authenticated_user_can_create_issue(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'Login page throws 500 on empty email',
            'description' => 'Reproduces consistently on Firefox and Chrome when submitting the login form with an empty email field.',
            'priority' => 'high',
            'category_id' => $category->id,
        ]);

        $response->assertStatus(201);
    }

    /** SRS §8.2 I-01: created issue has correct defaults (status=open, visibility=private, summary_status=pending). */
    public function test_created_issue_has_correct_defaults(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'Billing export corrupts UTF-8 characters',
            'description' => 'CSV export replaces accented characters with question marks.',
            'priority' => 'medium',
            'category_id' => $category->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.summary_status', 'pending')
            ->assertJsonPath('data.user_id', $user->id);
    }

    /** SRS §8.2 I-01: response includes category object (id, name, slug), not just category_id. */
    public function test_create_response_includes_category_object(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'Sidebar navigation collapses on tablet viewport',
            'description' => 'Sidebar becomes inaccessible at 768px viewport width.',
            'priority' => 'low',
            'category_id' => $category->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'category' => ['id', 'name', 'slug'],
                    'user' => ['id', 'name'],
                ],
            ]);
    }

    /** SRS §8.2 I-01: create sets needs_attention=true for high priority. */
    public function test_create_computes_needs_attention_for_high_priority(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'Payment gateway returns 503 during peak hours',
            'description' => 'Affects 30% of checkout attempts between 18:00 and 21:00 UTC.',
            'priority' => 'critical',
            'category_id' => $category->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.needs_attention', true);
    }

    /** SRS §8.2 I-01: GenerateSummaryJob is dispatched on create. */
    public function test_create_dispatches_generate_summary_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'Webhook signatures fail validation after key rotation',
            'description' => 'All inbound webhooks from Stripe return 401 after the API key was rotated last Thursday.',
            'priority' => 'high',
            'category_id' => $category->id,
        ])->assertStatus(201);

        Queue::assertPushed(GenerateSummaryJob::class);
    }

    /** SRS §8.2 I-02: unauthenticated request to create returns 401. */
    public function test_unauthenticated_user_cannot_create_issue(): void
    {
        $category = Category::factory()->create();

        $this->postJson('/api/issues', [
            'title' => 'Unauthorized attempt',
            'description' => 'This should be rejected.',
            'priority' => 'low',
            'category_id' => $category->id,
        ])->assertStatus(401);
    }

    // =========================================================================
    // StoreIssueRequest — Validation
    // =========================================================================

    /** Validation: title is required. */
    public function test_create_fails_when_title_is_missing(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/api/issues', [
            'description' => 'No title provided.',
            'priority' => 'low',
            'category_id' => $category->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    /** Validation: title max 255 characters. */
    public function test_create_fails_when_title_exceeds_255_characters(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/api/issues', [
            'title' => str_repeat('A', 256),
            'description' => 'Title is too long.',
            'priority' => 'low',
            'category_id' => $category->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    /** Validation: description is required. */
    public function test_create_fails_when_description_is_missing(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'Missing description issue',
            'priority' => 'low',
            'category_id' => $category->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    /** Validation: priority is required and must be a valid enum value. */
    public function test_create_fails_when_priority_is_invalid(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'Invalid priority issue',
            'description' => 'Priority value is not in the enum.',
            'priority' => 'urgent',
            'category_id' => $category->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['priority']);
    }

    /** Validation: category_id must exist in categories table. */
    public function test_create_fails_when_category_id_does_not_exist(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'Non-existent category issue',
            'description' => 'Category ID references nothing.',
            'priority' => 'low',
            'category_id' => 99999,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    /** Validation: visibility must be a valid enum if provided. */
    public function test_create_fails_when_visibility_is_invalid(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'Invalid visibility issue',
            'description' => 'Visibility value not in enum.',
            'priority' => 'low',
            'category_id' => $category->id,
            'visibility' => 'internal',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['visibility']);
    }

    /** Validation: deadline_at must be after now. */
    public function test_create_fails_when_deadline_is_in_the_past(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'Past deadline issue',
            'description' => 'Deadline is already in the past.',
            'priority' => 'low',
            'category_id' => $category->id,
            'deadline_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['deadline_at']);
    }

    /** Validation: visibility is optional — issue creates without it using default. */
    public function test_create_succeeds_without_optional_visibility(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->postJson('/api/issues', [
            'title' => 'No visibility specified',
            'description' => 'Should default to private.',
            'priority' => 'low',
            'category_id' => $category->id,
        ])->assertStatus(201)
            ->assertJsonPath('data.visibility', 'private');
    }

    // =========================================================================
    // GET /api/issues — List (with filters + pagination)
    // =========================================================================

    /** SRS §8.2 I-04: authenticated user sees their own issues in the list. */
    public function test_authenticated_user_can_list_their_issues(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson('/api/issues');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonFragment(['id' => $issue->id]);
    }

    /** SRS §8.2 I-04: list response includes comments_count (not full comments array). */
    public function test_list_response_includes_comments_count_not_comments_array(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        Comment::factory()->count(3)->for($issue)->for(User::factory()->create())->create();

        $response = $this->actingAs($user)->getJson('/api/issues');

        $response->assertStatus(200);

        $issueData = collect($response->json('data'))->firstWhere('id', $issue->id);
        $this->assertArrayHasKey('comments_count', $issueData);
        $this->assertEquals(3, $issueData['comments_count']);
        $this->assertArrayNotHasKey('comments', $issueData);
    }

    /** SRS §8.2 I-02: unauthenticated user cannot list issues. */
    public function test_unauthenticated_user_cannot_list_issues(): void
    {
        $this->getJson('/api/issues')->assertStatus(401);
    }

    /** SRS §8.2 / SPEC §5.6 I-13: filter by status returns only matching issues. */
    public function test_list_filters_by_status(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();

        $openIssue = Issue::factory()->for($user)->for($category)->open()->create();
        $resolvedIssue = Issue::factory()->for($user)->for($category)->resolved()->create();

        $response = $this->actingAs($user)->getJson('/api/issues?status=open');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($openIssue->id));
        $this->assertFalse($ids->contains($resolvedIssue->id));
    }

    /** SRS §8.2 / SPEC §5.6 I-13: filter by priority returns only matching issues. */
    public function test_list_filters_by_priority(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();

        $highIssue = Issue::factory()->for($user)->for($category)->highPriority()->create();
        $lowIssue = Issue::factory()->for($user)->for($category)->priority(Priority::Low)->create();

        $response = $this->actingAs($user)->getJson('/api/issues?priority=high');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($highIssue->id));
        $this->assertFalse($ids->contains($lowIssue->id));
    }

    /** SRS §8.2 / SPEC §5.6 I-13: filter by category slug resolves to category_id. */
    public function test_list_filters_by_category_slug(): void
    {
        $user = User::factory()->create();
        $billingCategory = Category::factory()->create(['name' => 'Billing Issues']);
        $techCategory = Category::factory()->create(['name' => 'Technical Problems']);

        $billingIssue = Issue::factory()->for($user)->for($billingCategory)->create();
        $techIssue = Issue::factory()->for($user)->for($techCategory)->create();

        // Use the slug that was auto-generated from the name
        $slug = $billingCategory->slug;

        $response = $this->actingAs($user)->getJson("/api/issues?category={$slug}");

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($billingIssue->id));
        $this->assertFalse($ids->contains($techIssue->id));
    }

    /** SRS §8.2 / SPEC §5.6: combined filters (status + priority + category) all applied. */
    public function test_list_supports_combined_filters(): void
    {
        $user = User::factory()->create();
        $billingCategory = Category::factory()->create(['name' => 'Account Billing']);
        $techCategory = Category::factory()->create(['name' => 'Technical Support']);

        // This issue should match all three filters
        $matchingIssue = Issue::factory()
            ->for($user)
            ->for($billingCategory)
            ->open()
            ->highPriority()
            ->create();

        // These should not match
        Issue::factory()->for($user)->for($billingCategory)->open()->priority(Priority::Low)->create();
        Issue::factory()->for($user)->for($techCategory)->open()->highPriority()->create();
        Issue::factory()->for($user)->for($billingCategory)->resolved()->highPriority()->create();

        $slug = $billingCategory->slug;
        $response = $this->actingAs($user)->getJson("/api/issues?status=open&priority=high&category={$slug}");

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($matchingIssue->id));
        $this->assertCount(1, $ids);
    }

    /** SPEC §5.6: invalid filter value is silently ignored — no 422. */
    public function test_list_ignores_invalid_status_filter_silently(): void
    {
        $user = User::factory()->create();
        Issue::factory()->for($user)->create();

        $this->actingAs($user)->getJson('/api/issues?status=nonexistent_status')
            ->assertStatus(200);
    }

    /** SPEC §5.6: invalid priority filter value is silently ignored — no 422. */
    public function test_list_ignores_invalid_priority_filter_silently(): void
    {
        $user = User::factory()->create();
        Issue::factory()->for($user)->create();

        $this->actingAs($user)->getJson('/api/issues?priority=not_a_priority')
            ->assertStatus(200);
    }

    /** SPEC §5.6: unknown category slug in filter is silently ignored — no 422, returns all. */
    public function test_list_ignores_unknown_category_slug_silently(): void
    {
        $user = User::factory()->create();
        Issue::factory()->for($user)->create();

        $this->actingAs($user)->getJson('/api/issues?category=nonexistent-slug')
            ->assertStatus(200);
    }

    /** SRS §8.2 I-17: pagination — 15 items per page. */
    public function test_list_paginates_at_15_per_page(): void
    {
        $user = User::factory()->create();
        Issue::factory()->count(30)->for($user)->create();

        $page1 = $this->actingAs($user)->getJson('/api/issues?page=1');
        $page2 = $this->actingAs($user)->getJson('/api/issues?page=2');

        $page1->assertStatus(200);
        $page2->assertStatus(200);

        $this->assertCount(15, $page1->json('data'));
        $this->assertCount(15, $page2->json('data'));

        // No ID overlap between pages
        $page1Ids = collect($page1->json('data'))->pluck('id');
        $page2Ids = collect($page2->json('data'))->pluck('id');
        $this->assertEmpty($page1Ids->intersect($page2Ids));
    }

    /** SRS §8.2 I-17: pagination meta contains correct total and per_page. */
    public function test_list_pagination_meta_is_correct(): void
    {
        $user = User::factory()->create();
        Issue::factory()->count(30)->for($user)->create();

        $response = $this->actingAs($user)->getJson('/api/issues');

        $response->assertStatus(200)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 30);
    }

    /** SRS §8.2 I-16: soft-deleted issues do not appear in the list. */
    public function test_soft_deleted_issue_is_excluded_from_list(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        $issue->delete();

        $response = $this->actingAs($user)->getJson('/api/issues');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($issue->id));
    }

    /** SRS §8.2: list only shows issues accessible by the requesting user (owned, shared, or public). */
    public function test_list_excludes_other_users_private_issues(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        Issue::factory()->for($owner)->private()->create();

        $response = $this->actingAs($other)->getJson('/api/issues');

        $response->assertStatus(200)
            ->assertJsonPath('meta.total', 0);
    }

    /** SRS §8.2: list includes public issues from other users. */
    public function test_list_includes_public_issues_from_other_users(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $publicIssue = Issue::factory()->for($owner)->public()->create();

        $response = $this->actingAs($other)->getJson('/api/issues');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($publicIssue->id));
    }

    // =========================================================================
    // GET /api/issues/{id} — Show
    // =========================================================================

    /** SRS §8.2 I-05: authenticated owner can view their issue. */
    public function test_owner_can_view_their_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson("/api/issues/{$issue->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $issue->id);
    }

    /** SRS §8.2 I-05: show response includes comments with comment.user (not just count). */
    public function test_show_response_includes_comments_with_user(): void
    {
        $owner = User::factory()->create();
        $commenter = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        Comment::factory()->for($issue)->for($commenter)->create();

        $response = $this->actingAs($owner)->getJson("/api/issues/{$issue->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'comments' => [
                        '*' => [
                            'user' => ['id', 'name'],
                        ],
                    ],
                ],
            ]);
    }

    /** SRS §8.2 I-05: show response includes category and user objects (not IDs only). */
    public function test_show_response_includes_category_and_user_objects(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $response = $this->actingAs($user)->getJson("/api/issues/{$issue->id}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'category' => ['id', 'name', 'slug'],
                    'user' => ['id', 'name'],
                ],
            ]);
    }

    /** SRS §8.2 I-02: unauthenticated show returns 401. */
    public function test_unauthenticated_user_cannot_view_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->getJson("/api/issues/{$issue->id}")->assertStatus(401);
    }

    /** SRS §8.2 I-06: user cannot view a private issue they do not own or share. */
    public function test_user_cannot_view_private_issue_they_do_not_own(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();

        $this->actingAs($other)->getJson("/api/issues/{$issue->id}")
            ->assertStatus(403);
    }

    /** SRS §8.2: user with view-share can view the issue. */
    public function test_user_with_view_share_can_view_issue(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $issue = Issue::factory()->for($owner)->private()->create();
        IssueShare::factory()->for($issue)->for($viewer, 'user')->view()->create();

        $this->actingAs($viewer)->getJson("/api/issues/{$issue->id}")
            ->assertStatus(200);
    }

    /** SRS §8.2 I-16: soft-deleted issue returns 404 on direct view. */
    public function test_soft_deleted_issue_returns_404_on_show(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        $issue->delete();

        $this->actingAs($user)->getJson("/api/issues/{$issue->id}")
            ->assertStatus(404);
    }

    /** SRS §8.2: non-existent issue returns 404. */
    public function test_show_returns_404_for_nonexistent_issue(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/issues/99999')
            ->assertStatus(404);
    }

    // =========================================================================
    // PATCH /api/issues/{id} — Update
    // =========================================================================

    /** SRS §8.2 I-04: owner can update their issue. */
    public function test_owner_can_update_their_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $response = $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'title' => 'Updated title after investigation',
            'updated_at' => $issue->updated_at->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated title after investigation');
    }

    /** SRS §8.2 I-02: unauthenticated update returns 401. */
    public function test_unauthenticated_user_cannot_update_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->patchJson("/api/issues/{$issue->id}", [
            'title' => 'Should not update',
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(401);
    }

    /** SRS §8.2: user without edit-share cannot update. */
    public function test_user_without_edit_share_cannot_update_issue(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();

        $this->actingAs($other)->patchJson("/api/issues/{$issue->id}", [
            'title' => 'Unauthorized update attempt',
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(403);
    }

    /** SRS §8.2: user with edit-share can update. */
    public function test_user_with_edit_share_can_update_issue(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        IssueShare::factory()->for($issue)->for($editor, 'user')->edit()->create();

        $this->actingAs($editor)->patchJson("/api/issues/{$issue->id}", [
            'title' => 'Editor updated this title',
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(200);
    }

    /** SRS §8.2 I-12: optimistic locking — stale updated_at returns 409. */
    public function test_update_with_stale_updated_at_returns_409(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        // Use a timestamp that is clearly in the past relative to the actual updated_at
        $staleTimestamp = $issue->updated_at->subSeconds(30)->toIso8601String();

        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'title' => 'Concurrent update conflict',
            'updated_at' => $staleTimestamp,
        ])->assertStatus(409);
    }

    /** SRS §8.2 I-12: optimistic locking — matching updated_at succeeds. */
    public function test_update_with_correct_updated_at_succeeds(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'title' => 'No conflict here',
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(200);
    }

    /** SRS §8.2 I-12: optimistic locking — two sequential updates, second is rejected if stale. */
    public function test_concurrent_updates_second_request_rejected_when_stale(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();
        $originalTimestamp = $issue->updated_at->toIso8601String();

        // First update succeeds
        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'title' => 'First updater wins',
            'updated_at' => $originalTimestamp,
        ])->assertStatus(200);

        // Second update uses original (now stale) timestamp — must be rejected
        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'title' => 'Second updater loses',
            'updated_at' => $originalTimestamp,
        ])->assertStatus(409);
    }

    /** SRS §8.2: updating description resets summary_status to pending and dispatches job. */
    public function test_update_description_resets_summary_status_and_dispatches_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->summaryReady()->create();

        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'description' => 'Completely revised description with new reproduction steps and logs.',
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(200)
            ->assertJsonPath('data.summary_status', 'pending');

        Queue::assertPushed(GenerateSummaryJob::class);
    }

    /** SRS §8.2: updating status only does NOT dispatch GenerateSummaryJob. */
    public function test_update_status_only_does_not_dispatch_summary_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'status' => 'in_progress',
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(200);

        Queue::assertNotPushed(GenerateSummaryJob::class);
    }

    /** SRS §8.2: updating priority recomputes needs_attention. */
    public function test_update_priority_recomputes_needs_attention(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->priority(Priority::Low)->create();

        $this->assertFalse($issue->fresh()->needs_attention);

        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'priority' => 'critical',
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(200)
            ->assertJsonPath('data.needs_attention', true);
    }

    // =========================================================================
    // UpdateIssueRequest — Validation
    // =========================================================================

    /** Validation: all update fields are optional (empty patch allowed). */
    public function test_update_with_no_fields_is_valid(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(200);
    }

    /** Validation: title max 255 characters on update. */
    public function test_update_fails_when_title_exceeds_255_characters(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'title' => str_repeat('Z', 256),
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    /** Validation: priority must be a valid enum if provided on update. */
    public function test_update_fails_when_priority_is_invalid_enum(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'priority' => 'super_urgent',
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['priority']);
    }

    /** Validation: category_id must exist if provided on update. */
    public function test_update_fails_when_category_id_does_not_exist(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'category_id' => 88888,
            'updated_at' => $issue->updated_at->toIso8601String(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    /** Validation: updated_at is required on update (needed for optimistic locking). */
    public function test_update_fails_when_updated_at_is_missing(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->patchJson("/api/issues/{$issue->id}", [
            'title' => 'Missing updated_at',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['updated_at']);
    }

    // =========================================================================
    // DELETE /api/issues/{id} — Soft Delete
    // =========================================================================

    /** SRS §8.2 I-16: owner can soft delete their issue. */
    public function test_owner_can_soft_delete_their_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->deleteJson("/api/issues/{$issue->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('issues', ['id' => $issue->id]);
    }

    /** SRS §8.2 I-02: unauthenticated delete returns 401. */
    public function test_unauthenticated_user_cannot_delete_issue(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->deleteJson("/api/issues/{$issue->id}")->assertStatus(401);
    }

    /** SRS §8.2: non-owner cannot delete issue (even with edit share). */
    public function test_non_owner_with_edit_share_cannot_delete_issue(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        IssueShare::factory()->for($issue)->for($editor, 'user')->edit()->create();

        $this->actingAs($editor)->deleteJson("/api/issues/{$issue->id}")
            ->assertStatus(403);
    }

    /** SRS §8.2 I-16: soft-deleted issue is excluded from list after deletion. */
    public function test_soft_deleted_issue_is_excluded_from_list_after_deletion(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->deleteJson("/api/issues/{$issue->id}")->assertStatus(204);

        $response = $this->actingAs($user)->getJson('/api/issues');
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($issue->id));
    }

    /** SRS §8.2 I-16: soft-deleted issue returns 404 on show after deletion. */
    public function test_soft_deleted_issue_returns_404_on_show_after_deletion(): void
    {
        $user = User::factory()->create();
        $issue = Issue::factory()->for($user)->create();

        $this->actingAs($user)->deleteJson("/api/issues/{$issue->id}")->assertStatus(204);

        $this->actingAs($user)->getJson("/api/issues/{$issue->id}")->assertStatus(404);
    }

    // =========================================================================
    // N+1 Query Prevention (SRS §NFR-01 / Technical Guidance §7)
    // =========================================================================

    /** SRS §NFR-01: list endpoint does not cause N+1 queries on category/user relationships. */
    public function test_list_does_not_produce_n_plus_1_queries(): void
    {
        $user = User::factory()->create();
        Issue::factory()->count(10)->for($user)->create();

        DB::enableQueryLog();

        $this->actingAs($user)->getJson('/api/issues')->assertStatus(200);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Baseline: auth query + issues + category (eager) + user (eager) + count
        // Should be well under 20 queries regardless of issue count
        $this->assertLessThan(20, $queryCount, "Expected fewer than 20 queries for list of 10 issues, got {$queryCount}");
    }

    /** SRS §NFR-01: show endpoint does not produce N+1 queries on comments/user relationships. */
    public function test_show_does_not_produce_n_plus_1_queries(): void
    {
        $owner = User::factory()->create();
        $issue = Issue::factory()->for($owner)->create();
        $commenters = User::factory()->count(5)->create();
        foreach ($commenters as $commenter) {
            Comment::factory()->for($issue)->for($commenter)->create();
        }

        DB::enableQueryLog();

        $this->actingAs($owner)->getJson("/api/issues/{$issue->id}")->assertStatus(200);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // auth + issue + category (eager) + user (eager) + comments.user (eager)
        // Should be well under 15 queries regardless of comment count
        $this->assertLessThan(15, $queryCount, "Expected fewer than 15 queries for show with 5 comments, got {$queryCount}");
    }
}
