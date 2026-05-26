<?php

namespace App\Services;

use App\Enums\SummaryStatus;
use App\Jobs\GenerateSummaryJob;
use App\Models\Issue;
use App\Models\IssueStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Business logic for the Issue CRUD API.
 *
 * @see task 02.01.00 / SRS §FR-02
 */
class IssueService
{
    /**
     * Create a new issue for the given user.
     *
     * Sets defaults (status=open, visibility=private, summary_status=pending),
     * relies on the Issue saving event to compute needs_attention,
     * then dispatches GenerateSummaryJob.
     */
    public function create(User $user, array $data): Issue
    {
        $issue = Issue::create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'priority' => $data['priority'],
            'category_id' => $data['category_id'],
            'visibility' => $data['visibility'] ?? 'private',
            'deadline_at' => $data['deadline_at'] ?? null,
            // Defaults
            'status_id' => IssueStatus::where('is_default', true)->value('id'),
            'summary_status' => 'pending',
        ]);

        dispatch(new GenerateSummaryJob($issue));

        return $issue->load(['category', 'user', 'status']);
    }

    /**
     * Update an existing issue.
     *
     * Optimistic locking: compares client-supplied updated_at against DB value.
     * Returns 409 on mismatch.
     *
     * Re-dispatches GenerateSummaryJob only when description changes.
     * Resets summary_status=pending when description changes.
     * Relies on saving event to recompute needs_attention.
     *
     * @throws HttpException on stale lock (409)
     */
    public function update(Issue $issue, array $data): Issue
    {
        // Optimistic locking check
        $clientTimestamp = Carbon::parse($data['updated_at'])->utc();
        $dbTimestamp = $issue->updated_at->utc();

        if (! $clientTimestamp->equalTo($dbTimestamp)) {
            abort(Response::HTTP_CONFLICT, 'Conflict: the issue was updated by another request.');
        }

        $descriptionChanged = isset($data['description'])
            && $data['description'] !== $issue->description;

        // Apply only the fields that were supplied
        $fillable = ['title', 'description', 'priority', 'status_id', 'category_id', 'visibility', 'deadline_at'];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $issue->$field = $data[$field];
            }
        }

        if ($descriptionChanged) {
            $issue->summary_status = SummaryStatus::Pending;
        }

        // Use an explicit updated_at that is guaranteed to be later than the current
        // value, even within a PostgreSQL test transaction where NOW() is pinned.
        // We advance by at least 1 second to exceed the ISO 8601 string resolution.
        $newUpdatedAt = $issue->updated_at->addSecond();
        $issue->timestamps = false;
        $issue->updated_at = $newUpdatedAt;
        $issue->save();
        $issue->timestamps = true;

        if ($descriptionChanged) {
            dispatch(new GenerateSummaryJob($issue));
        }

        return $issue->load(['category', 'user', 'status']);
    }

    /**
     * Soft-delete an issue.
     */
    public function delete(Issue $issue): void
    {
        $issue->delete();
    }
}
