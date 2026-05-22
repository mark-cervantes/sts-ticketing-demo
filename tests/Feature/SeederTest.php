<?php

namespace Tests\Feature;

use App\Enums\SummaryStatus;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * SRS §6 I-01: DatabaseSeeder runs without error via migrate:fresh --seed.
     *
     * This is the integration-level smoke test — it re-runs migrate:fresh inside
     * the test body, which is intentionally slow but proves the full seed pipeline
     * is clean from scratch.
     */
    public function test_database_seeder_runs_cleanly(): void
    {
        $result = Artisan::call('migrate:fresh', ['--seed' => true]);

        $this->assertSame(0, $result);
    }

    /**
     * SRS §6 I-02: Seeded data meets minimum record counts from SPEC.
     *
     * Pinned counts: 5+ users, exactly 6 categories, 15+ issues,
     * 30+ comments, 2+ issue shares.
     */
    public function test_seeded_data_meets_minimum_counts(): void
    {
        Artisan::call('db:seed');

        $this->assertGreaterThanOrEqual(5, User::count());
        $this->assertSame(6, Category::count());
        $this->assertGreaterThanOrEqual(15, Issue::count());
        $this->assertGreaterThanOrEqual(30, Comment::count());
        $this->assertGreaterThanOrEqual(2, IssueShare::count());
    }

    /**
     * SRS §6 I-03: At least one issue with summary_status=ready has non-null
     * summary text and suggested_next_action.
     */
    public function test_seeded_data_includes_summary_ready_issue(): void
    {
        Artisan::call('db:seed');

        $ready = Issue::where('summary_status', SummaryStatus::Ready)->first();

        $this->assertNotNull($ready, 'Expected at least one issue with summary_status=ready');
        $this->assertNotNull($ready->summary, 'Ready issue must have summary text');
        $this->assertNotEmpty($ready->summary);
        $this->assertNotNull($ready->suggested_next_action);
    }

    /**
     * SRS §6 I-04: At least 3 issues with needs_attention=true exist after seeding.
     */
    public function test_seeded_data_includes_needs_attention_issues(): void
    {
        Artisan::call('db:seed');

        $this->assertGreaterThanOrEqual(3, Issue::where('needs_attention', true)->count());
    }

    /**
     * SRS §6 I-05: All 6 categories from SPEC §4.3 are present by exact name.
     */
    public function test_seeded_categories_match_spec(): void
    {
        Artisan::call('db:seed');

        $expected = ['billing', 'technical', 'account', 'general', 'bug', 'feature-request'];

        foreach ($expected as $name) {
            $this->assertTrue(
                Category::where('name', $name)->exists(),
                "Expected category '{$name}' to exist after seeding"
            );
        }
    }
}
