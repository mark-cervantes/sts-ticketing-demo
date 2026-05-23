<?php

namespace App\Console\Commands;

use App\Enums\SummaryStatus;
use App\Jobs\GenerateSummaryJob;
use App\Models\Issue;
use Illuminate\Console\Command;

/**
 * Reset stuck summaries and re-dispatch GenerateSummaryJob for each.
 *
 * "Stuck" means the issue is in `processing` (interrupted mid-job) or
 * `pending` (never picked up — e.g. Horizon was down).
 *
 * Safe to run multiple times — already-retried issues will simply be
 * in `pending` again and will be dispatched once more (idempotent).
 * Issues with `ready` or `failed` status are never touched.
 *
 * @see SPEC §4.2 / ADR-002 / SummaryStatus
 */
class RetrySummariesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'summaries:retry-stuck';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset interrupted (processing) summaries to pending and re-dispatch GenerateSummaryJob for all pending issues';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Step 1: Reset any `processing` issues to `pending`.
        // These were interrupted mid-job (e.g. Horizon restart) and will
        // never complete on their own — they must be retried.
        Issue::where('summary_status', SummaryStatus::Processing)
            ->update(['summary_status' => SummaryStatus::Pending]);

        // Step 2: Dispatch a job for every `pending` issue (includes those
        // just reset above and any that never started).
        $count = 0;

        Issue::where('summary_status', SummaryStatus::Pending)
            ->chunkById(200, function ($issues) use (&$count): void {
                foreach ($issues as $issue) {
                    dispatch(new GenerateSummaryJob($issue));
                    $count++;
                }
            });

        $this->info("Retried {$count} stuck summaries.");

        return Command::SUCCESS;
    }
}
