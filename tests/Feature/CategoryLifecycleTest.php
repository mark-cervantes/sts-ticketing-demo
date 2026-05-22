<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Issue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category Lifecycle — Integration Test (full multi-step user path).
 *
 * SRS §8.2 I-09: category lifecycle — attempt duplicate name → create new
 * inline → assign to issue → delete unused → attempt delete of used → 409.
 *
 * Each step is an HTTP request against the real DB so that the full constraint
 * chain (validation, slug generation, deletion guard, FK) is exercised together.
 */
class CategoryLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SRS §8.2 I-09: full category lifecycle from duplicate-rejection through
     * deletion guard.
     *
     * Steps:
     *  1. Seed an existing category ("Deployment Failures").
     *  2. Attempt to create a case-insensitive duplicate → 422.
     *  3. Create a new, distinct category ("Release Management") → 201 with slug.
     *  4. Create an issue that references the new category.
     *  5. Create a second category with no issues ("Unused Category").
     *  6. Delete the unused category → 204; confirm DB removal.
     *  7. Attempt to delete the category that has an issue → 409 with count.
     *  8. Confirm the guarded category is still present in the DB.
     */
    public function test_category_lifecycle_i09(): void
    {
        $user = User::factory()->create();

        // ── Step 1: Seed an existing category ────────────────────────────────
        $existingCategory = Category::factory()->create(['name' => 'Deployment Failures']);

        // ── Step 2: Attempt case-insensitive duplicate → 422 ─────────────────
        $this->actingAs($user)->postJson('/api/categories', [
            'name' => 'deployment failures',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        // Verify no extra row was created
        $this->assertDatabaseCount('categories', 1);

        // ── Step 3: Create a new valid category → 201 with slug ──────────────
        $createResponse = $this->actingAs($user)->postJson('/api/categories', [
            'name' => 'Release Management',
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'slug'])
            ->assertJsonPath('name', 'Release Management')
            ->assertJsonPath('slug', 'release-management');

        $newCategoryId = $createResponse->json('id');
        $this->assertDatabaseHas('categories', [
            'id'   => $newCategoryId,
            'name' => 'Release Management',
            'slug' => 'release-management',
        ]);

        // ── Step 4: Create an issue referencing the new category ──────────────
        Issue::factory()->for($user)->create(['category_id' => $newCategoryId]);

        // ── Step 5: Create a second category with no issues ───────────────────
        $unusedCreateResponse = $this->actingAs($user)->postJson('/api/categories', [
            'name' => 'Unused Category',
        ]);

        $unusedCreateResponse->assertStatus(201);
        $unusedCategoryId = $unusedCreateResponse->json('id');

        // ── Step 6: Delete the unused category → 204 ─────────────────────────
        $this->actingAs($user)->deleteJson("/api/categories/{$unusedCategoryId}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('categories', ['id' => $unusedCategoryId]);

        // ── Step 7: Attempt to delete the category that has issues → 409 ──────
        $deleteResponse = $this->actingAs($user)->deleteJson("/api/categories/{$newCategoryId}");

        $deleteResponse->assertStatus(409)
            ->assertJsonPath('message', 'Cannot delete: 1 issues use this category');

        // ── Step 8: Confirm the guarded category is still in the DB ───────────
        $this->assertDatabaseHas('categories', ['id' => $newCategoryId]);
    }

    /**
     * SRS §8.3: GET /api/categories returns all categories in name-sorted order
     * after a multi-create sequence, confirming sort is server-side.
     */
    public function test_list_reflects_sorted_order_after_multiple_creates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/categories', ['name' => 'Security'])->assertStatus(201);
        $this->actingAs($user)->postJson('/api/categories', ['name' => 'Authentication'])->assertStatus(201);
        $this->actingAs($user)->postJson('/api/categories', ['name' => 'Performance'])->assertStatus(201);

        $response = $this->actingAs($user)->getJson('/api/categories');

        $response->assertStatus(200);

        $names = collect($response->json())->pluck('name')->values()->all();
        $this->assertSame(['Authentication', 'Performance', 'Security'], $names);
    }
}
