<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CommentReaction — one emoji reaction per user per comment.
 *
 * Unique constraint: (comment_id, user_id, emoji) — toggle semantics enforced at DB level.
 */
class CommentReaction extends Model
{
    protected $fillable = ['comment_id', 'user_id', 'emoji'];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** The comment this reaction belongs to. */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /** The user who left this reaction. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
