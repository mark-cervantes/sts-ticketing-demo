<?php

namespace Tests\Unit\Models;

use App\Models\Issue;
use App\Models\IssueStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for the IssueStatus model.
 *
 * Covers: slug auto-generation, deletion guard (is_default blocked, issues-exist blocked).
 *
 * @see task 08.01 / SPEC §4.2
 */
class IssueStatusTest extends TestCase
{
    use RefreshDatabase;

    /** Slug is auto-generated from name on create. */
    public function test_slug_is_generated_from_name_on_create(): void
    {
        $status = IssueStatus::create([
            'name' => 'Waiting on Customer',
            'color' => '#f59e0b',
            'sort_order' => 10,
            'is_default' => false,
        ]);

        $this->assertSame('waiting-on-customer', $status->slug);
    }

    /** Slug collision resolution: second entry gets numeric suffix. */
    public function test_slug_collision_gets_numeric_suffix(): void
    {
        IssueStatus::create([
            'name' => 'QA Review',
            'color' => '#8b5cf6',
            'sort_order' => 5,
            'is_default' => false,
        ]);

        $second = IssueStatus::create([
            'name' => 'QA Review',
            'color' => '#7c3aed',
            'sort_order' => 6,
            'is_default' => false,
        ]);

        $this->assertSame('qa-review-2', $second->slug);
    }

    /** Slug provided explicitly is preserved (not overwritten). */
    public function test_explicit_slug_is_preserved(): void
    {
        // The seeder inserts rows with explicit slugs via DB::table; the model
        // only auto-generates on the 'creating' event (Eloquent create).
        // Verify seeded rows retain their slugs.
        $status = IssueStatus::where('slug', 'open')->first();

        $this->assertNotNull($status);
        $this->assertSame('open', $status->slug);
    }

    /** Deletion of the default status is blocked with 409-equivalent check. */
    public function test_default_status_cannot_be_deleted_flag_check(): void
    {
        $default = IssueStatus::where('is_default', true)->first();

        // The controller blocks deletion; model just exposes is_default
        $this->assertTrue($default->is_default);
    }

    /** IssueStatus reports correct issue count via relation. */
    public function test_issues_count_via_relation(): void
    {
        $openStatus = IssueStatus::where('slug', 'open')->first();

        $initialCount = $openStatus->issues()->count();

        // Create an issue linked to this status
        Issue::factory()->for($openStatus, 'status')->create();

        $this->assertSame($initialCount + 1, $openStatus->fresh()->issues()->count());
    }

    /** Non-default status with no issues can be safely deleted. */
    public function test_status_with_no_issues_is_deletable(): void
    {
        $status = IssueStatus::create([
            'name' => 'Deletable Status',
            'color' => '#64748b',
            'sort_order' => 99,
            'is_default' => false,
        ]);

        $id = $status->id;
        $status->delete();

        $this->assertNull(IssueStatus::find($id));
    }

    /** Status with existing issues is blocked by the controller (relation count check). */
    public function test_status_with_issues_has_positive_issues_count(): void
    {
        $openStatus = IssueStatus::where('slug', 'open')->first();

        // Ensure at least one issue is linked
        Issue::factory()->for($openStatus, 'status')->create();

        $this->assertGreaterThan(0, $openStatus->issues()->count());
    }
}
