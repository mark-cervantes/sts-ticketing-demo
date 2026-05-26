<?php

namespace Tests\Feature;

use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the Status CRUD API.
 *
 * Covers: list ordered by sort_order, create with color, rename,
 * delete guard (is_default → 409, issues exist → 409), is_default toggle.
 *
 * @see task 08.01 / SRS §FR-02
 */
class StatusApiTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // GET /api/statuses
    // -------------------------------------------------------------------------

    /** Unauthenticated requests are rejected. */
    public function test_unauthenticated_user_cannot_list_statuses(): void
    {
        $this->getJson('/api/statuses')->assertStatus(401);
    }

    /** Statuses are returned ordered by sort_order. */
    public function test_list_returns_statuses_ordered_by_sort_order(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/statuses');

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertIsArray($data);

        // Verify ascending sort_order
        for ($i = 0; $i < count($data) - 1; $i++) {
            $this->assertLessThanOrEqual($data[$i + 1]['sort_order'], $data[$i]['sort_order']);
        }
    }

    /** List includes all expected fields. */
    public function test_list_returns_expected_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/statuses');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => ['id', 'name', 'slug', 'color', 'sort_order', 'is_default'],
            ]);
    }

    /** Three default statuses are seeded. */
    public function test_list_includes_three_seeded_default_statuses(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/statuses');
        $response->assertStatus(200);

        $slugs = collect($response->json())->pluck('slug')->all();
        $this->assertContains('open', $slugs);
        $this->assertContains('in_progress', $slugs);
        $this->assertContains('resolved', $slugs);
    }

    // -------------------------------------------------------------------------
    // POST /api/statuses
    // -------------------------------------------------------------------------

    /** Unauthenticated requests are rejected. */
    public function test_unauthenticated_user_cannot_create_status(): void
    {
        $this->postJson('/api/statuses', ['name' => 'QA Review'])->assertStatus(401);
    }

    /** Authenticated user can create a status with a name and color. */
    public function test_authenticated_user_can_create_status_with_color(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/statuses', [
            'name' => 'QA Review',
            'color' => '#8b5cf6',
            'sort_order' => 5,
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'QA Review',
                'slug' => 'qa-review',
                'color' => '#8b5cf6',
                'sort_order' => 5,
            ]);

        $this->assertDatabaseHas('statuses', ['slug' => 'qa-review']);
    }

    /** Slug is auto-generated from name. */
    public function test_slug_is_auto_generated_from_name(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/statuses', [
            'name' => 'Waiting on Customer',
            'color' => '#f59e0b',
        ])->assertStatus(201)
            ->assertJsonFragment(['slug' => 'waiting-on-customer']);
    }

    /** Name is required. */
    public function test_name_is_required_for_create(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/statuses', [
            'color' => '#f59e0b',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /** Color must be a valid 7-character hex string. */
    public function test_color_must_be_valid_hex(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/statuses', [
            'name' => 'Test Status',
            'color' => 'red',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['color']);
    }

    /** Duplicate name (case-insensitive) is rejected. */
    public function test_duplicate_name_case_insensitive_is_rejected(): void
    {
        $user = User::factory()->create();

        // 'Open' is already seeded
        $this->actingAs($user)->postJson('/api/statuses', [
            'name' => 'OPEN',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // -------------------------------------------------------------------------
    // PUT /api/statuses/{status}
    // -------------------------------------------------------------------------

    /** Unauthenticated requests are rejected. */
    public function test_unauthenticated_user_cannot_update_status(): void
    {
        $status = IssueStatus::where('slug', 'open')->first();

        $this->putJson("/api/statuses/{$status->id}", ['name' => 'New Name'])->assertStatus(401);
    }

    /** Authenticated user can rename a status. */
    public function test_authenticated_user_can_rename_status(): void
    {
        $user = User::factory()->create();
        $status = IssueStatus::where('slug', 'open')->first();

        $response = $this->actingAs($user)->putJson("/api/statuses/{$status->id}", [
            'name' => 'Open Ticket',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Open Ticket']);

        $this->assertDatabaseHas('statuses', ['id' => $status->id, 'name' => 'Open Ticket']);
    }

    /** Authenticated user can update color and sort_order. */
    public function test_authenticated_user_can_update_color_and_sort_order(): void
    {
        $user = User::factory()->create();
        $status = IssueStatus::where('slug', 'open')->first();

        $this->actingAs($user)->putJson("/api/statuses/{$status->id}", [
            'color' => '#ef4444',
            'sort_order' => 10,
        ])->assertStatus(200)
            ->assertJsonFragment(['color' => '#ef4444', 'sort_order' => 10]);
    }

    /** Duplicate name on update (excluding self) is rejected. */
    public function test_update_rejects_duplicate_name_excluding_self(): void
    {
        $user = User::factory()->create();
        $open = IssueStatus::where('slug', 'open')->first();

        // Try to rename 'Open' to 'In Progress' (name that already exists)
        $this->actingAs($user)->putJson("/api/statuses/{$open->id}", [
            'name' => 'In Progress',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /** Updating a status's own name (same case) is allowed (exclude self). */
    public function test_update_same_name_case_variant_is_allowed(): void
    {
        $user = User::factory()->create();
        $open = IssueStatus::where('slug', 'open')->first();

        // Updating 'Open' to 'Open' (same as self) should succeed
        $this->actingAs($user)->putJson("/api/statuses/{$open->id}", [
            'name' => 'Open',
        ])->assertStatus(200);
    }

    /** Setting is_default=true on a non-default status clears it from all others. */
    public function test_setting_is_default_clears_other_defaults(): void
    {
        $user = User::factory()->create();
        $inProgress = IssueStatus::where('slug', 'in_progress')->first();

        // Verify 'open' is currently the default
        $this->assertTrue(IssueStatus::where('slug', 'open')->first()->is_default);
        $this->assertFalse($inProgress->is_default);

        $this->actingAs($user)->putJson("/api/statuses/{$inProgress->id}", [
            'is_default' => true,
        ])->assertStatus(200);

        // Now in_progress should be default; open should not
        $this->assertTrue($inProgress->fresh()->is_default);
        $this->assertFalse(IssueStatus::where('slug', 'open')->first()->is_default);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/statuses/{status}
    // -------------------------------------------------------------------------

    /** Unauthenticated requests are rejected. */
    public function test_unauthenticated_user_cannot_delete_status(): void
    {
        $status = IssueStatus::where('slug', 'open')->first();

        $this->deleteJson("/api/statuses/{$status->id}")->assertStatus(401);
    }

    /** Deleting the default status returns 409. */
    public function test_delete_default_status_returns_409(): void
    {
        $user = User::factory()->create();
        $default = IssueStatus::where('is_default', true)->first();

        $this->actingAs($user)->deleteJson("/api/statuses/{$default->id}")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Cannot delete the default status.']);
    }

    /** Deleting a status with existing issues returns 409 with count. */
    public function test_delete_status_with_issues_returns_409_with_count(): void
    {
        $user = User::factory()->create();

        // Create a non-default status with an issue
        $status = IssueStatus::create([
            'name' => 'Escalated',
            'color' => '#dc2626',
            'sort_order' => 10,
            'is_default' => false,
        ]);

        Issue::factory()->for($status, 'status')->create();

        $this->actingAs($user)->deleteJson("/api/statuses/{$status->id}")
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'Cannot delete: 1 issues use this status.']);
    }

    /** Deleting a non-default status with no issues succeeds. */
    public function test_authenticated_user_can_delete_unused_non_default_status(): void
    {
        $user = User::factory()->create();

        $status = IssueStatus::create([
            'name' => 'Temporary Status',
            'color' => '#64748b',
            'sort_order' => 99,
            'is_default' => false,
        ]);

        $this->actingAs($user)->deleteJson("/api/statuses/{$status->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('statuses', ['id' => $status->id]);
    }
}
