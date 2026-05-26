<?php

namespace App\Observers;

use App\Jobs\GenerateSummaryJob;
use App\Models\Comment;
use App\Models\Issue;

/**
 * Dispatches a summary regeneration job whenever a comment is created.
 *
 * The conversation thread is integral to the summary (SRS §7.3), so every
 * new comment should trigger a fresh synthesis. GenerateSummaryJob has retry
 * logic and a rules-driver fallback, so it is safe to dispatch unconditionally.
 *
 * @see Task store-prompts-in-db
 */
class CommentObserver
{
    /**
     * After a comment is saved, regenerate the parent issue's summary so it
     * incorporates the latest conversation thread.
     */
    public function created(Comment $comment): void
    {
        /** @var Issue|null $issue */
        $issue = $comment->issue;

        if ($issue !== null) {
            GenerateSummaryJob::dispatch($issue);
        }
    }
}
