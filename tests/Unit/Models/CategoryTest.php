<?php

namespace Tests\Unit\Models;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPEC §4.3 / ADR-005
 * Category model: slug auto-generation and collision handling.
 */
class CategoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Slug is automatically generated from the category name on the creating event.
     * "Bug Reports" → "bug-reports"
     */
    public function test_slug_auto_generated_from_name(): void
    {
        $category = Category::create(['name' => 'Bug Reports']);

        $this->assertSame('bug-reports', $category->slug);
    }

    /**
     * SRS §4.3 / ADR-006 — categories.name is unique at the DB level.
     *
     * Slug collisions are resolved by suffix-incrementing, using near-identical names
     * that each normalize to the same slug through Str::slug() but remain distinct
     * strings at the DB level (compatible with the unique constraint on name).
     *
     * 'Bug Reports'  → slug: bug-reports   (first)
     * 'Bug  Reports' → slug: bug-reports-2 (double-space, same slug root)
     * 'BUG REPORTS'  → slug: bug-reports-3 (uppercase, same slug root)
     */
    public function test_slug_collision_handled(): void
    {
        Category::create(['name' => 'Bug Reports']);
        $second = Category::create(['name' => 'Bug  Reports']);

        $this->assertSame('bug-reports-2', $second->slug);
    }

    /**
     * SRS §4.3 — A third near-identical name that slugifies to the same root
     * increments to -3, proving the suffix loop works beyond the first collision.
     *
     * 'Bug Reports'  → bug-reports   (distinct name, unique DB row)
     * 'Bug  Reports' → bug-reports-2 (distinct name, unique DB row)
     * 'BUG REPORTS'  → bug-reports-3 (distinct name, unique DB row)
     */
    public function test_slug_triple_collision_increments_correctly(): void
    {
        Category::create(['name' => 'Bug Reports']);
        Category::create(['name' => 'Bug  Reports']);
        $third = Category::create(['name' => 'BUG REPORTS']);

        $this->assertSame('bug-reports-3', $third->slug);
    }
}
