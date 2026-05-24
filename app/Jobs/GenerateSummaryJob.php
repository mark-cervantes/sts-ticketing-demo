<?php

namespace App\Jobs;

use App\Enums\SummaryStatus;
use App\Events\SummaryCompleted;
use App\Exceptions\SummaryGenerationException;
use App\Facades\Summary;
use App\Models\Issue;
use App\Services\Summary\SummaryResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Async job that generates an AI summary for a support issue.
 *
 * Flow (SRS §7.5):
 *  1. Refresh issue from DB (stale guard).
 *  2. Load category relation if not already loaded.
 *  3. Mark status = Processing.
 *  4. Attempt Summary::generate() via the configured driver.
 *  5. On LLM failure (SummaryGenerationException):
 *       - If retries remain (async queue), rethrow so Laravel requeues.
 *       - On the final attempt, fall back to the deterministic rules driver.
 *  6. Persist summary + next_action + status = Ready.
 *  7. Fire SummaryCompleted event.
 *
 * On permanent failure (all retries exhausted AND fallback threw):
 *  Laravel calls failed() → status = Failed.
 *
 * @see SRS §7.5 / ADR-002
 */
class GenerateSummaryJob implements ShouldQueue
{
    use Queueable;

    /** @var int Maximum attempts before permanent failure. */
    public int $tries = 3;

    /**
     * Backoff intervals in seconds between retries.
     *
     * @var array<int>
     */
    public array $backoff = [10, 30, 90];

    /**
     * Constructor — signature must remain stable (IssueService depends on it).
     */
    public function __construct(public readonly Issue $issue) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Refresh from DB so we work with the latest state.
        $this->issue->refresh();

        // Ensure the category relation is loaded (lost during serialisation).
        if (! $this->issue->relationLoaded('category')) {
            $this->issue->load('category');
        }

        // Mark as in-progress.
        $this->issue->summary_status = SummaryStatus::Processing;
        $this->issue->save();

        $result = $this->generateWithFallback();

        $this->issue->summary = $result->summary;
        $this->issue->suggested_next_action = $result->suggestedNextAction;
        $this->issue->suggested_next_ticket = $result->suggestedNextTicket;
        $this->issue->summary_status = SummaryStatus::Ready;
        $this->issue->save();

        event(new SummaryCompleted($this->issue));
    }

    /**
     * Attempt primary driver; on failure, retry if possible, otherwise fall
     * back to the deterministic rules driver.
     *
     * On an async queue: throws SummaryGenerationException when retries remain
     * so Laravel requeues the job. On the final attempt (or sync queue where
     * attempts() never increments past 1 for a requeue), falls back to rules.
     *
     * @throws SummaryGenerationException when the rules fallback also fails (permanent)
     */
    private function generateWithFallback(): SummaryResult
    {
        try {
            return Summary::generate($this->issue);
        } catch (SummaryGenerationException $e) {
            // Rethrow only when there are attempts remaining AND we are not on
            // the sync driver (sync never re-queues, so rethrowing is wasteful).
            $isSyncQueue = config('queue.default') === 'sync';

            if (! $isSyncQueue && $this->attempts() < $this->tries) {
                throw $e;
            }

            // Final attempt or sync queue — use the deterministic rules driver.
            Log::warning('GenerateSummaryJob: primary driver failed; using rules fallback.', [
                'issue_id' => $this->issue->id,
                'driver' => config('summary.default'),
                'error' => $e->getMessage(),
            ]);

            return Summary::driver('rules')->generate($this->issue);
        }
    }

    /**
     * Handle a job failure — called by Laravel after all retries are exhausted
     * and the final-attempt fallback itself threw (should not happen with the
     * deterministic rules driver, but must be handled per ADR-002 line 71).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateSummaryJob: permanent failure after all retries.', [
            'issue_id' => $this->issue->id,
            'error' => $exception->getMessage(),
        ]);

        $this->issue->summary_status = SummaryStatus::Failed;
        $this->issue->save();
    }
}
