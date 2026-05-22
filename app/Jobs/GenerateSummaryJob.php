<?php

namespace App\Jobs;

use App\Models\Issue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stub job — real implementation in task 02.04.00.
 *
 * Accepts an Issue so dispatch() can be called with the model.
 * Queue::assertPushed(GenerateSummaryJob::class) works against this stub.
 */
class GenerateSummaryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Issue $issue) {}

    /**
     * Execute the job.
     *
     * Empty stub — task 02.04.00 fills in the real implementation.
     */
    public function handle(): void {}
}
