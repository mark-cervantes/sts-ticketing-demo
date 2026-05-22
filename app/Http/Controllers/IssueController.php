<?php

namespace App\Http\Controllers;

use App\Enums\Priority;
use App\Enums\Status;
use App\Http\Requests\StoreIssueRequest;
use App\Http\Requests\UpdateIssueRequest;
use App\Http\Resources\IssueResource;
use App\Models\Category;
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

    /**
     * GET /api/issues — list issues accessible by the authenticated user.
     *
     * Filters: ?status=open, ?priority=high, ?category=slug (all optional, silently ignored if invalid)
     * Sort: needs_attention desc, priority desc, created_at desc
     * Pagination: 15 per page
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

        // Filter: status (silently ignore invalid enum values)
        if ($request->filled('status')) {
            $status = Status::tryFrom($request->query('status'));
            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        // Filter: priority (silently ignore invalid enum values)
        if ($request->filled('priority')) {
            $priority = Priority::tryFrom($request->query('priority'));
            if ($priority !== null) {
                $query->where('priority', $priority);
            }
        }

        // Filter: category slug → resolve to category_id (silently ignore unknown slugs)
        if ($request->filled('category')) {
            $categoryId = Category::where('slug', $request->query('category'))->value('id');
            if ($categoryId !== null) {
                $query->where('category_id', $categoryId);
            }
        }

        // Default sort: needs_attention desc, priority desc, created_at desc
        // Use the aliased column so DISTINCT is happy in PostgreSQL
        $query->orderByDesc('needs_attention')
            ->orderByDesc('priority_order')
            ->orderByDesc('created_at');

        $issues = $query->paginate(15);

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

        $issue->load(['comments.user', 'category', 'user']);

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
