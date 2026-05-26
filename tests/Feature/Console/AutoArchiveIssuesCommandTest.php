<?php

namespace Tests\Feature\Console;

use App\Models\Issue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Feature tests for issues:auto-archive command.
 *
 * @see vault/SPEC §4.2 / Task 10.04
 */
class AutoArchiveIssuesCommandTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // Happy path
    // =========================================================================

    /** Archives old resolved issues that exceed the after_days threshold. */
    public function test_command_archives_old_resolved_issues(): void
    {
        Config::set('issues.auto_archive', [
            'enabled' => true,
            'statuses' => ['resolved'],
            'after_days' => 7,
        ]);

        $oldResolved = Issue::factory()->resolved()->create([
            'updated_at' => now()->subDays(10),
        ]);

        $this->artisan('issues:auto-archive')
            ->assertExitCode(0);

        $this->assertNotNull(Issue::find($oldResolved->id)->archived_at);
    }

    /** Does NOT archive recent resolved issues still within the threshold. */
    public function test_command_skips_recently_resolved_issues(): void
    {
        Config::set('issues.auto_archive', [
            'enabled' => true,
            'statuses' => ['resolved'],
            'after_days' => 7,
        ]);

        $recentResolved = Issue::factory()->resolved()->create([
            'updated_at' => now()->subDays(3),
        ]);

        $this->artisan('issues:auto-archive')
            ->assertExitCode(0);

        $this->assertNull(Issue::find($recentResolved->id)->archived_at);
    }

    /** Does NOT archive old issues that are not in a configured terminal status. */
    public function test_command_skips_non_configured_statuses(): void
    {
        Config::set('issues.auto_archive', [
            'enabled' => true,
            'statuses' => ['resolved'],
            'after_days' => 7,
        ]);

        $oldOpen = Issue::factory()->open()->create([
            'updated_at' => now()->subDays(10),
        ]);
        $oldInProgress = Issue::factory()->inProgress()->create([
            'updated_at' => now()->subDays(10),
        ]);

        $this->artisan('issues:auto-archive')
            ->assertExitCode(0);

        $this->assertNull(Issue::find($oldOpen->id)->archived_at);
        $this->assertNull(Issue::find($oldInProgress->id)->archived_at);
    }

    /** Skips already-archived issues (scopeActive excludes them). */
    public function test_command_skips_already_archived_issues(): void
    {
        Config::set('issues.auto_archive', [
            'enabled' => true,
            'statuses' => ['resolved'],
            'after_days' => 7,
        ]);

        $archivedAt = now()->subHours(2);
        $issue = Issue::factory()->resolved()->create([
            'archived_at' => $archivedAt,
            'updated_at' => now()->subDays(10),
        ]);

        $this->artisan('issues:auto-archive')
            ->assertExitCode(0);

        // archived_at should not have been overwritten
        $this->assertEquals(
            $archivedAt->toDateTimeString(),
            Issue::find($issue->id)->archived_at->toDateTimeString(),
        );
    }

    /** Outputs the count of archived issues. */
    public function test_command_outputs_archived_count(): void
    {
        Config::set('issues.auto_archive', [
            'enabled' => true,
            'statuses' => ['resolved'],
            'after_days' => 7,
        ]);

        Issue::factory()->resolved()->create(['updated_at' => now()->subDays(10)]);
        Issue::factory()->resolved()->create(['updated_at' => now()->subDays(8)]);
        Issue::factory()->resolved()->create(['updated_at' => now()->subDays(3)]); // recent, skip

        $this->artisan('issues:auto-archive')
            ->expectsOutput('Archived 2 issues.')
            ->assertExitCode(0);
    }

    // =========================================================================
    // Config guards
    // =========================================================================

    /** Skips entirely when auto_archive.enabled is false. */
    public function test_command_skips_when_disabled_in_config(): void
    {
        Config::set('issues.auto_archive', [
            'enabled' => false,
            'statuses' => ['resolved'],
            'after_days' => 7,
        ]);

        $oldResolved = Issue::factory()->resolved()->create([
            'updated_at' => now()->subDays(10),
        ]);

        $this->artisan('issues:auto-archive')
            ->assertExitCode(0);

        $this->assertNull(Issue::find($oldResolved->id)->archived_at);
    }

    /** Returns 0 count gracefully when no statuses match the slugs. */
    public function test_command_handles_nonexistent_status_slugs_gracefully(): void
    {
        Config::set('issues.auto_archive', [
            'enabled' => true,
            'statuses' => ['nonexistent_status'],
            'after_days' => 7,
        ]);

        // Should not throw, should exit 0
        $this->artisan('issues:auto-archive')
            ->assertExitCode(0);
    }

    /** Respects configurable after_days threshold. */
    public function test_command_respects_configurable_after_days(): void
    {
        Config::set('issues.auto_archive', [
            'enabled' => true,
            'statuses' => ['resolved'],
            'after_days' => 30,
        ]);

        // 10 days old — under the 30-day threshold, should NOT archive
        $recentResolved = Issue::factory()->resolved()->create([
            'updated_at' => now()->subDays(10),
        ]);
        // 35 days old — over the 30-day threshold, should archive
        $oldResolved = Issue::factory()->resolved()->create([
            'updated_at' => now()->subDays(35),
        ]);

        $this->artisan('issues:auto-archive')
            ->assertExitCode(0);

        $this->assertNull(Issue::find($recentResolved->id)->archived_at);
        $this->assertNotNull(Issue::find($oldResolved->id)->archived_at);
    }

    /** Soft-deleted issues are excluded from auto-archive. */
    public function test_command_excludes_soft_deleted_issues(): void
    {
        Config::set('issues.auto_archive', [
            'enabled' => true,
            'statuses' => ['resolved'],
            'after_days' => 7,
        ]);

        $issue = Issue::factory()->resolved()->create([
            'updated_at' => now()->subDays(10),
        ]);
        $issue->delete();

        $this->artisan('issues:auto-archive')
            ->expectsOutput('Archived 0 issues.')
            ->assertExitCode(0);
    }
}
