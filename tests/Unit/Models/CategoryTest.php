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
     * Second category with the same name gets a -2 suffix (not -1).
     * Conventional slug collision resolution per Technical Guidance §3.
     */
    public function test_slug_collision_handled(): void
    {
        Category::create(['name' => 'Bug Reports']);
        $second = Category::create(['name' => 'Bug Reports']);

        $this->assertSame('bug-reports-2', $second->slug);
    }

    /**
     * Bonus: a third collision increments to -3.
     */
    public function test_slug_triple_collision_increments_correctly(): void
    {
        Category::create(['name' => 'Bug Reports']);
        Category::create(['name' => 'Bug Reports']);
        $third = Category::create(['name' => 'Bug Reports']);

        $this->assertSame('bug-reports-3', $third->slug);
    }
}
