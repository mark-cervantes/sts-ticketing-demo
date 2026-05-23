<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIssueRequest;
use App\Http\Requests\UpdateIssueRequest;
use App\Http\Resources\IssueResource;
use App\Models\Issue;
use App\Services\IssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Response;

/**
 * Issue CRUD API controller.
 *
 * Thin: delegates to IssueService, uses IssuePolicy for authorization.
 *
 * @see task 02.01.00 / SRS §FR-02
 */
class IssueController extends Controller
{
    public function __construct(private readonly IssueService $service) {}

    /** Allowed sort fields for ?sort= query param (G2). */
    private const SORTABLE = ['created_at', 'updated_at', 'priority', 'deadline_at'];

    /**
     * GET /api/issues — list issues accessible by the authenticated user.
     *
     * Filters: ?status=open, ?priority=high, ?category=slug (all optional, silently ignored if invalid)
     * Sort: ?sort=created_at (default: needs_attention→priority→created_at), ?direction=asc|desc (default: desc)
     * Pagination: ?per_page=N (default 15, max 50), ?page=N
     */
    public function index(Request $request): ResourceCollection
    {
        $this->authorize('viewAny', Issue::class);

        $query = Issue::query()
            ->select('issues.*')
            ->selectRaw("CASE priority WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END AS priority_order")
            ->with(['category', 'user'])
            ->withCount('comments')
            ->accessibleBy($request->user());

        // Filters — via named model scopes (G5); each scope silently ignores invalid values
        $query->filterByStatus($request->query('status'))
            ->filterByPriority($request->query('priority'))
            ->filterByCategory($request->query('category'));

        // Sort (G2 + G3)
        $sort = $request->query('sort');
        $direction = $request->query('direction', 'desc');

        // Validate direction — silently fall back to desc if not asc|desc (G3)
        if (! in_array($direction, ['asc', 'desc'], strict: true)) {
            $direction = 'desc';
        }

        if ($sort !== null && in_array($sort, self::SORTABLE, strict: true)) {
            // User-requested single-column sort (G2)
            if ($sort === 'priority') {
                // priority_order alias carries numeric CASE result
                $query->orderBy('priority_order', $direction);
            } elseif ($sort === 'deadline_at') {
                // Null deadlines always sort last regardless of direction (G2 + spec)
                $query->orderByRaw("deadline_at IS NULL, deadline_at {$direction}");
            } else {
                $query->orderBy($sort, $direction);
            }
        } else {
            // Default sort: needs_attention desc, priority desc, created_at desc
            $query->orderByDesc('needs_attention')
                ->orderByDesc('priority_order')
                ->orderByDesc('created_at');
        }

        // per_page — clamp to [1, 50], default 15 (G1)
        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));

        $issues = $query->paginate($perPage);

        // Preserve all query params in pagination links (G4)
        $issues->appends($request->query());

        return IssueResource::collection($issues);
    }

    /**
     * POST /api/issues — create a new issue.
     */
    public function store(StoreIssueRequest $request): JsonResponse
    {
        $this->authorize('create', Issue::class);

        $issue = $this->service->create($request->user(), $request->validated());

        return (new IssueResource($issue))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/issues/{issue} — show a single issue with comments.
     */
    public function show(Issue $issue): IssueResource
    {
        $this->authorize('view', $issue);

        $issue->load(['comments.user', 'comments.reactions.user', 'category', 'user']);

        return new IssueResource($issue);
    }

    /**
     * PATCH /api/issues/{issue} — update an issue (with optimistic locking).
     */
    public function update(UpdateIssueRequest $request, Issue $issue): IssueResource
    {
        $this->authorize('update', $issue);

        $issue = $this->service->update($issue, $request->validated());

        return new IssueResource($issue);
    }

    /**
     * DELETE /api/issues/{issue} — soft delete.
     */
    public function destroy(Issue $issue): Response
    {
        $this->authorize('delete', $issue);

        $this->service->delete($issue);

        return response()->noContent();
    }
}
