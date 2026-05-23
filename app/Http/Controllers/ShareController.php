<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreShareRequest;
use App\Http\Requests\UpdateShareRequest;
use App\Models\Issue;
use App\Models\IssueShare;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Share management endpoints — all mutations are owner-only (IssuePolicy::share).
 *
 * Routes (shallow resource under issues):
 *   GET    /api/issues/{issue}/shares          → index
 *   POST   /api/issues/{issue}/shares          → store
 *   PATCH  /api/shares/{share}                 → update  (shallow)
 *   DELETE /api/shares/{share}                 → destroy (shallow)
 *
 * @see task 04.01.00 / SPEC §4.5 / ADR-007
 */
class ShareController extends Controller
{
    /**
     * GET /api/issues/{issue}/shares
     *
     * List all shares for an issue. Owner only.
     */
    public function index(Issue $issue): JsonResponse
    {
        $this->authorize('share', $issue);

        $shares = $issue->shares()->with('user')->get();

        return response()->json([
            'data' => $shares->map(fn (IssueShare $share) => [
                'id' => $share->id,
                'permission' => $share->permission->value,
                'created_at' => $share->created_at->toIso8601String(),
                'user' => [
                    'id' => $share->user->id,
                    'name' => $share->user->name,
                    'email' => $share->user->email,
                ],
            ]),
        ]);
    }

    /**
     * POST /api/issues/{issue}/shares
     *
     * Share an issue with a user (upsert by issue+user — updates permission if already shared).
     * Owner only. Cannot share with self.
     */
    public function store(StoreShareRequest $request, Issue $issue): JsonResponse
    {
        $this->authorize('share', $issue);

        $validated = $request->validated();

        /** @var User $targetUser */
        $targetUser = User::where('email', $validated['email'])->first();

        if ($targetUser === null) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['email' => ['No registered user found with this email address.']],
            ], 422);
        }

        $share = IssueShare::updateOrCreate(
            ['issue_id' => $issue->id, 'user_id' => $targetUser->id],
            ['permission' => $validated['permission']],
        );

        return response()->json([
            'data' => [
                'id' => $share->id,
                'permission' => $share->permission->value,
                'created_at' => $share->created_at->toIso8601String(),
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                ],
            ],
        ], 201);
    }

    /**
     * PATCH /api/shares/{share}
     *
     * Update the permission level of an existing share. Owner only.
     */
    public function update(UpdateShareRequest $request, IssueShare $share): JsonResponse
    {
        $this->authorize('share', $share->issue);

        $share->update($request->validated());

        return response()->json([
            'data' => [
                'id' => $share->id,
                'permission' => $share->permission->value,
                'created_at' => $share->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * DELETE /api/shares/{share}
     *
     * Remove a share. Owner only. FK cascade handles any downstream cleanup.
     */
    public function destroy(IssueShare $share): JsonResponse
    {
        $this->authorize('share', $share->issue);

        $share->delete();

        return response()->json(null, 204);
    }
}
