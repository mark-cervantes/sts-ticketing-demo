<?php

namespace App\Http\Controllers;

use App\Http\Requests\MigrateAndDeleteStatusRequest;
use App\Http\Requests\StoreStatusRequest;
use App\Http\Requests\UpdateStatusRequest;
use App\Models\Issue;
use App\Models\IssueStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Status CRUD API controller.
 *
 * Thin: no service layer needed — logic is self-contained.
 * Auth enforced by 'auth' middleware on the route group.
 * No Policy needed — statuses are open to all authenticated users (SRS §FR-08).
 *
 * @see task 08.01 / SRS §FR-02
 */
class StatusController extends Controller
{
    /**
     * GET /api/statuses — list all statuses ordered by sort_order.
     */
    public function index(): JsonResponse
    {
        $statuses = IssueStatus::orderBy('sort_order')->get();

        return response()->json($statuses);
    }

    /**
     * POST /api/statuses — create a new status with auto-generated slug.
     */
    public function store(StoreStatusRequest $request): JsonResponse
    {
        $data = $request->validated();

        $status = IssueStatus::create($data);

        return response()->json($status, 201);
    }

    /**
     * PUT/PATCH /api/statuses/{status} — update name, color, sort_order, or is_default.
     *
     * When setting is_default=true, clears the flag from all other statuses in a transaction.
     */
    public function update(UpdateStatusRequest $request, IssueStatus $status): JsonResponse
    {
        $data = $request->validated();

        if (! empty($data['is_default'])) {
            DB::transaction(function () use ($status, $data): void {
                // Clear is_default from all other statuses
                IssueStatus::where('id', '!=', $status->id)->update(['is_default' => false]);
                $status->update($data);
            });
        } else {
            $status->update($data);
        }

        return response()->json($status->fresh());
    }

    /**
     * DELETE /api/statuses/{status} — delete only if not default and no issues reference it.
     *
     * Returns 409 with message if the status is the default.
     * Returns 409 with count if issues reference it.
     */
    public function destroy(IssueStatus $status): Response|JsonResponse
    {
        if ($status->is_default) {
            return response()->json(
                ['message' => 'Cannot delete the default status.'],
                409
            );
        }

        $count = $status->issues()->count();

        if ($count > 0) {
            return response()->json(
                ['message' => "Cannot delete: {$count} issues use this status."],
                409
            );
        }

        $status->delete();

        return response()->noContent();
    }

    /**
     * POST /api/statuses/{status}/migrate-and-delete
     *
     * Migrate issues to a target status (or delete them), then delete this status.
     * Runs inside a transaction so a partial failure leaves no orphaned issues.
     *
     * Request body (at least one required for statuses with issues):
     *   - { target_status_id: <int> }  — bulk-update issues to that status
     *   - { delete_issues: true }       — bulk-delete all issues on this status
     *
     * Returns 409 when the status is the default (cannot delete the default).
     * Returns 422 when the status has issues but neither key was provided.
     */
    public function migrateAndDelete(MigrateAndDeleteStatusRequest $request, IssueStatus $status): Response|JsonResponse
    {
        if ($status->is_default) {
            return response()->json(
                ['message' => 'Cannot delete the default status.'],
                409
            );
        }

        $issueCount = $status->issues()->count();

        if ($issueCount > 0) {
            $targetStatusId = $request->input('target_status_id');
            $deleteIssues = (bool) $request->input('delete_issues', false);

            // Neither option provided — caller must specify what to do with the issues
            if (! $targetStatusId && ! $deleteIssues) {
                return response()->json([
                    'message' => "This status has {$issueCount} issues. Provide target_status_id or delete_issues=true.",
                ], 422);
            }

            DB::transaction(function () use ($status, $targetStatusId, $deleteIssues): void {
                if ($deleteIssues) {
                    // Force-delete so the row is physically removed before the FK check on status delete.
                    // Eloquent soft-delete would leave the row pointing at the status, triggering RESTRICT.
                    Issue::where('status_id', $status->id)->forceDelete();
                } else {
                    Issue::where('status_id', $status->id)->update(['status_id' => $targetStatusId]);
                }
                $status->delete();
            });
        } else {
            $status->delete();
        }

        return response()->noContent();
    }
}
