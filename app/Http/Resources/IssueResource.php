<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Issue API resource — shapes the JSON response for the Issue CRUD API.
 *
 * List response: includes comments_count (not full comments array).
 * Show response: includes comments with comments.user.
 *
 * @see task 02.01.00 / SRS §FR-03 §FR-04
 */
class IssueResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority->value,
            // Backward compat: emit slug as 'status' string (frontend currently reads this)
            'status' => $this->status->slug,
            'status_id' => $this->status_id,
            'status_obj' => [
                'id' => $this->status->id,
                'name' => $this->status->name,
                'slug' => $this->status->slug,
                'color' => $this->status->color,
                'sort_order' => $this->status->sort_order,
                'is_default' => $this->status->is_default,
            ],
            'visibility' => $this->visibility->value,
            'summary_status' => $this->summary_status->value,
            'summary' => $this->summary,
            'suggested_next_action' => $this->suggested_next_action,
            'suggested_next_ticket' => $this->suggested_next_ticket,
            'needs_attention' => $this->needs_attention,
            'deadline_at' => $this->deadline_at?->toIso8601String(),
            'user_id' => $this->user_id,
            'category_id' => $this->category_id,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            // Owner (always eager-loaded)
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],

            // Category (always eager-loaded)
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ],

            // comments_count present on list (withCount); absent on show
            $this->mergeWhen($this->comments_count !== null, fn () => [
                'comments_count' => $this->comments_count,
            ]),

            // comments present on show (whenLoaded); absent on list
            'comments' => $this->whenLoaded('comments', function () use ($request) {
                $userId = $request->user()?->id;

                return $this->comments->map(fn ($comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'created_at' => $comment->created_at->toIso8601String(),
                    'user' => [
                        'id' => $comment->user->id,
                        'name' => $comment->user->name,
                    ],
                    'reactions_summary' => $comment->reactionsSummary($userId),
                ]);
            }),

            // Permission gates for UI affordances
            'can' => [
                'view' => $request->user() ? Gate::allows('view', $this->resource) : false,
                'update' => $request->user() ? Gate::allows('update', $this->resource) : false,
                'comment' => $request->user() ? Gate::allows('comment', $this->resource) : false,
                'delete' => $request->user() ? Gate::allows('delete', $this->resource) : false,
            ],
        ];
    }
}
