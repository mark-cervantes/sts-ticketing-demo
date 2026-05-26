<?php

namespace App\Http\Controllers;

use App\Http\Resources\IssueResource;
use App\Models\Issue;
use App\Models\IssueStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles archive, unarchive, and bulk-archive actions for issues.
 *
 * Authorization: reuses the `update` policy (owner OR share with edit permission)
 * per Architecture Note 4 — no new policy method needed.
 *
 * @see vault/SPEC §4.2 / ADR-007
 */
class IssueArchiveController extends Controller
{
    /**
     * PATCH /api/issues/{issue}/archive
     *
     * Archives a single issue. Only resolved issues can be archived.
     */
    public function archive(Request $request, Issue $issue): IssueResource|JsonResponse
    {
        $this->authorize('update', $issue);

        // Ensure issue is resolved before archiving (Business Rule §4.2)
        if (! $issue->status || $issue->status->slug !== 'resolved') {
            return response()->json(
                ['message' => 'Only resolved issues can be archived.'],
                422,
            );
        }

        $issue->archive();
        $issue->load(['category', 'user', 'status']);

        return new IssueResource($issue);
    }

    /**
     * PATCH /api/issues/{issue}/unarchive
     *
     * Restores an archived issue to the active board.
     */
    public function unarchive(Request $request, Issue $issue): IssueResource
    {
        $this->authorize('update', $issue);

        $issue->unarchive();
        $issue->load(['category', 'user', 'status']);

        return new IssueResource($issue);
    }

    /**
     * POST /api/issues/bulk-archive
     *
     * Archives all active, resolved issues accessible by the authenticated user.
     * Returns the count of issues that were archived.
     *
     * @see Architecture Note 7 — returns { archived_count: N }
     */
    public function bulkArchive(Request $request): JsonResponse
    {
        $user = $request->user();
        $statuses = config('issues.auto_archive.statuses', ['resolved']);

        // Resolve slugs to IDs defensively — same pattern as scopeFilterByStatus
        $statusIds = IssueStatus::whereIn('slug', $statuses)->pluck('id');

        if ($statusIds->isEmpty()) {
            Log::warning('issues:bulk-archive — no status IDs found for slugs', ['slugs' => $statuses]);

            return response()->json(['archived_count' => 0]);
        }

        $count = Issue::query()
            ->accessibleBy($user)
            ->active()
            ->whereIn('status_id', $statusIds)
            ->update(['archived_at' => now()]);

        return response()->json(['archived_count' => $count]);
    }
}
