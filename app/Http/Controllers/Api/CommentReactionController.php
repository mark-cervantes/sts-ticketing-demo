<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ToggleCommentReactionRequest;
use App\Models\Comment;
use App\Models\CommentReaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles emoji reactions on comments.
 *
 * POST /api/comments/{comment}/reactions — toggle a reaction on/off.
 * GET  /api/comments/{comment}/reactions — list grouped reactions with user names.
 *
 * @see Task 07.04
 */
class CommentReactionController extends Controller
{
    /**
     * POST /api/comments/{comment}/reactions
     *
     * Toggles the authenticated user's reaction on the comment.
     * Returns whether the reaction was added or removed, plus updated counts.
     */
    public function toggle(ToggleCommentReactionRequest $request, Comment $comment): JsonResponse
    {
        $user = $request->user();
        $emoji = $request->validated()['emoji'];

        /** @var CommentReaction|null $existing */
        $existing = CommentReaction::where([
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'emoji' => $emoji,
        ])->first();

        if ($existing) {
            $existing->delete();
            $toggled = 'removed';
        } else {
            CommentReaction::create([
                'comment_id' => $comment->id,
                'user_id' => $user->id,
                'emoji' => $emoji,
            ]);
            $toggled = 'added';
        }

        // Reload fresh reactions for the response
        $comment->load('reactions.user');

        return response()->json([
            'toggled' => $toggled,
            'reactions' => $comment->reactionsSummary($user->id),
        ]);
    }

    /**
     * GET /api/comments/{comment}/reactions
     *
     * Returns grouped reactions for the comment with user names and a
     * per-auth-user "reacted" flag.
     */
    public function index(Request $request, Comment $comment): JsonResponse
    {
        $comment->load('reactions.user');

        return response()->json([
            'data' => $comment->reactionsSummary($request->user()?->id),
        ]);
    }
}
