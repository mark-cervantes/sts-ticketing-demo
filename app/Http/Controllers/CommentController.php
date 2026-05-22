<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCommentRequest;
use App\Models\Comment;
use App\Models\Issue;
use Illuminate\Http\JsonResponse;

/**
 * Comment creation endpoint.
 *
 * Thin: validates via StoreCommentRequest, authorizes via CommentPolicy,
 * creates the comment inline (no service needed for a single create).
 *
 * @see task 02.02.00 / SRS §FR-07
 */
class CommentController extends Controller
{
    /**
     * POST /api/issues/{issue}/comments
     *
     * Creates a comment on the given issue.
     * user_id is always set from the authenticated user — never from request input.
     */
    public function store(StoreCommentRequest $request, Issue $issue): JsonResponse
    {
        $this->authorize('create', [Comment::class, $issue]);

        /** @var Comment $comment */
        $comment = $issue->comments()->make($request->validated());
        $comment->user_id = $request->user()->id;
        $comment->save();

        $comment->load('user');

        return response()->json([
            'data' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'created_at' => $comment->created_at->toIso8601String(),
                'user' => [
                    'id' => $comment->user->id,
                    'name' => $comment->user->name,
                ],
            ],
        ], 201);
    }
}
