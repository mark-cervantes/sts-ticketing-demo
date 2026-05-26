<?php

namespace App\Console\Commands;

use App\Models\Issue;
use App\Models\IssueStatus;
use Illuminate\Console\Command;

/**
 * Periodically recalculates the needs_attention flag for all open/in-progress issues.
 *
 * Runs every 15 minutes via the scheduler. Excludes resolved and soft-deleted issues.
 * Only writes to the DB when the value actually changed (no unnecessary writes).
 * Uses updateQuietly() to avoid re-triggering the saving event.
 *
 * @see SPEC §6.6 / ADR-005 / BR-03
 */
class RecalculateAttentionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'issues:recalculate-attention';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate needs_attention flag for open and in-progress issues';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = 0;

        $statusIds = IssueStatus::whereIn('slug', ['open', 'in_progress'])->pluck('id');

        Issue::whereIn('status_id', $statusIds)
            ->chunkById(200, function ($issues) use (&$count) {
                foreach ($issues as $issue) {
                    $new = Issue::computeNeedsAttention($issue->priority, $issue->deadline_at);

                    if ($new !== (bool) $issue->needs_attention) {
                        $issue->updateQuietly(['needs_attention' => $new]);
                        $count++;
                    }
                }
            });

        $this->info("Updated {$count} issues.");

        return Command::SUCCESS;
    }
}
