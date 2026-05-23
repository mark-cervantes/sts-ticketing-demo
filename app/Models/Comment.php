<?php

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Comment model — immutable once posted, no updated_at.
 *
 * @see SPEC §4.4
 */
#[Fillable(['body'])]
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    /**
     * No updated_at column in the comments table per SPEC §4.4.
     */
    public const UPDATED_AT = null;

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** The issue this comment belongs to. */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** The user who posted this comment. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** All reactions on this comment. */
    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns grouped reaction counts for this comment, with optional
     * current-user "reacted" flag.
     *
     * @param  int|null  $forUserId  The user whose "reacted" flag to check.
     * @return array<string, array{count: int, users: list<string>, reacted: bool}>
     */
    public function reactionsSummary(?int $forUserId = null): array
    {
        /** @var Collection<int, CommentReaction> $reactions */
        $reactions = $this->reactions->load('user');

        $grouped = $reactions->groupBy('emoji');

        $summary = [];
        foreach ($grouped as $emoji => $group) {
            $summary[$emoji] = [
                'count' => $group->count(),
                'users' => $group->map(fn ($r) => $r->user->name)->values()->all(),
                'reacted' => $forUserId !== null && $group->contains('user_id', $forUserId),
            ];
        }

        return $summary;
    }
}
