<?php

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
