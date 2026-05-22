<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category API — Feature Tests (HTTP layer).
 *
 * SRS §8.3 / FR-08: category CRUD endpoints open to all authenticated users.
 *
 * Covers:
 *  - GET    /api/categories      (sorted list)
 *  - POST   /api/categories      (create with slug, case-insensitive uniqueness)
 *  - DELETE /api/categories/{id} (delete unused; guard used with 409)
 */
class CategoryApiTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // GET /api/categories — List
    // =========================================================================

    /** SRS §8.3: list returns all categories ordered alphabetically by name. */
    public function test_list_returns_all_categories_sorted_by_name(): void
    {
        $user = User::factory()->create();
        Category::factory()->create(['name' => 'Zebra Issues']);
        Category::factory()->create(['name' => 'Alpha Bugs']);
        Category::factory()->create(['name' => 'Middle Requests']);

        $response = $this->actingAs($user)->getJson('/api/categories');

        $response->assertStatus(200);

        $names = collect($response->json())->pluck('name')->values()->all();
        $this->assertSame(['Alpha Bugs', 'Middle Requests', 'Zebra Issues'], $names);
    }

    /** SRS §8.3: list response items include id, name, and slug fields. */
    public function test_list_response_items_have_correct_shape(): void
    {
        $user = User::factory()->create();
        Category::factory()->create(['name' => 'Performance']);

        $response = $this->actingAs($user)->getJson('/api/categories');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => ['id', 'name', 'slug'],
            ]);
    }

    /** SRS §8.3 / FR-08: unauthenticated request to list returns 401. */
    public function test_unauthenticated_user_cannot_list_categories(): void
    {
        $this->getJson('/api/categories')->assertStatus(401);
    }

    /** SRS §8.3: empty categories table returns empty array (not 404). */
    public function test_list_returns_empty_array_when_no_categories_exist(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/categories');

        $response->assertStatus(200)
            ->assertExactJson([]);
    }

    // =========================================================================
    // POST /api/categories — Create
    // =========================================================================

    /** SRS §8.3: authenticated user can create a category with a valid name. */
    public function test_authenticated_user_can_create_category_with_valid_name(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/categories', [
            'name' => 'Security Vulnerabilities',
        ]);

        $response->assertStatus(201);
    }

    /** SRS §8.3: create response includes id, name, and slug. */
    public function test_create_response_includes_id_name_and_slug(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/categories', [
            'name' => 'Performance Regression',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'slug']);
    }

    /** SRS §8.3: slug is auto-generated from name on create. */
    public function test_slug_is_auto_generated_from_name_on_create(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/categories', [
            'name' => 'Bug Reports',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('slug', 'bug-reports');
    }

    /** SRS §8.3 / FR-08: unauthenticated create returns 401. */
    public function test_unauthenticated_user_cannot_create_category(): void
    {
        $this->postJson('/api/categories', [
            'name' => 'Should Not Be Created',
        ])->assertStatus(401);
    }

    /** Validation: name is required. */
    public function test_create_fails_when_name_is_missing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /** Validation: name must not be empty string. */
    public function test_create_fails_when_name_is_empty_string(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/categories', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // =========================================================================
    // POST /api/categories — Case-insensitive uniqueness (Critical Path)
    // =========================================================================

    /**
     * SRS §8.3 / Technical Guidance §Case-Insensitive Uniqueness:
     * Exact-case duplicate name returns 422 with validation error on name.
     */
    public function test_create_exact_duplicate_name_returns_422(): void
    {
        $user = User::factory()->create();
        Category::factory()->create(['name' => 'Network Errors']);

        $this->actingAs($user)->postJson('/api/categories', [
            'name' => 'Network Errors',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * SRS §8.3 / Technical Guidance §Case-Insensitive Uniqueness:
     * Lowercase variant of an existing category name returns 422 (not a DB error).
     * "Bug Reports" exists → POST "bug reports" → 422 validation error on name.
     */
    public function test_create_case_insensitive_duplicate_name_returns_422(): void
    {
        $user = User::factory()->create();
        Category::factory()->create(['name' => 'Bug Reports']);

        $this->actingAs($user)->postJson('/api/categories', [
            'name' => 'bug reports',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * SRS §8.3 / Technical Guidance §Case-Insensitive Uniqueness:
     * Uppercase variant of an existing category name returns 422 (not a DB error).
     * "Bug Reports" exists → POST "BUG REPORTS" → 422 validation error on name.
     */
    public function test_create_uppercase_duplicate_name_returns_422(): void
    {
        $user = User::factory()->create();
        Category::factory()->create(['name' => 'Bug Reports']);

        $this->actingAs($user)->postJson('/api/categories', [
            'name' => 'BUG REPORTS',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * SRS §8.3: names differing only by whitespace trimming are treated as duplicates.
     * Leading/trailing spaces are trimmed before the uniqueness check.
     */
    public function test_create_trims_name_before_uniqueness_check(): void
    {
        $user = User::factory()->create();
        Category::factory()->create(['name' => 'API Issues']);

        $this->actingAs($user)->postJson('/api/categories', [
            'name' => '  API Issues  ',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // =========================================================================
    // DELETE /api/categories/{id} — Delete
    // =========================================================================

    /** SRS §8.3: authenticated user can delete a category that has no issues. */
    public function test_authenticated_user_can_delete_unused_category(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();

        $this->actingAs($user)->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    /** SRS §8.3 / FR-08: unauthenticated delete returns 401. */
    public function test_unauthenticated_user_cannot_delete_category(): void
    {
        $category = Category::factory()->create();

        $this->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(401);
    }

    /** SRS §8.3: delete returns 404 for non-existent category. */
    public function test_delete_returns_404_for_nonexistent_category(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->deleteJson('/api/categories/99999')
            ->assertStatus(404);
    }

    /**
     * SRS §8.3 / Technical Guidance §Deletion Guard:
     * Delete a category that has issues attached returns 409.
     * Response body contains the issue count in the message.
     */
    public function test_delete_category_with_issues_returns_409_with_count(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        Issue::factory()->count(3)->for($user)->for($category)->create();

        $response = $this->actingAs($user)->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(409);
        $this->assertStringContainsString('3', $response->json('message'));
    }

    /**
     * SRS §8.3 / Technical Guidance §Deletion Guard:
     * 409 response message matches the expected format:
     * "Cannot delete: {N} issues use this category"
     */
    public function test_delete_used_category_409_message_contains_correct_count(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        Issue::factory()->count(5)->for($user)->for($category)->create();

        $response = $this->actingAs($user)->deleteJson("/api/categories/{$category->id}");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Cannot delete: 5 issues use this category');
    }

    /**
     * SRS §8.3 / Technical Guidance §Deletion Guard:
     * Category is NOT deleted from the database when the guard fires.
     */
    public function test_delete_used_category_leaves_category_in_database(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        Issue::factory()->for($user)->for($category)->create();

        $this->actingAs($user)->deleteJson("/api/categories/{$category->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }
}
