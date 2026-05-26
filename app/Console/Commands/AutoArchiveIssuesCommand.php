<?php

namespace App\Console\Commands;

use App\Models\Issue;
use App\Models\IssueStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command: issues:auto-archive
 *
 * Archives active issues that have been in a configured terminal status for
 * longer than the configured threshold (default: resolved status, 7 days).
 *
 * Runs daily via the scheduler (routes/console.php).
 * Reads configuration from config/issues.php under the 'auto_archive' key.
 *
 * @see vault/SPEC §4.2 / config/issues.php
 */
class AutoArchiveIssuesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'issues:auto-archive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive resolved issues that have been inactive for longer than the configured threshold.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $config = config('issues.auto_archive', []);
        $enabled = $config['enabled'] ?? true;

        if (! $enabled) {
            $this->info('Auto-archive is disabled. Skipping.');

            return self::SUCCESS;
        }

        $statuses = $config['statuses'] ?? ['resolved'];
        $afterDays = (int) ($config['after_days'] ?? 7);

        // Resolve slugs → IDs defensively (same pattern as scopeFilterByStatus)
        $statusIds = IssueStatus::whereIn('slug', $statuses)->pluck('id');

        if ($statusIds->isEmpty()) {
            Log::warning('issues:auto-archive — no status IDs found for configured slugs', [
                'slugs' => $statuses,
            ]);
            $this->warn('No matching statuses found for slugs: '.implode(', ', $statuses).'. Skipping.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($afterDays);

        $count = Issue::query()
            ->active()
            ->whereIn('status_id', $statusIds)
            ->where('updated_at', '<', $cutoff)
            ->update(['archived_at' => now()]);

        $this->info("Archived {$count} issues.");

        return self::SUCCESS;
    }
}
