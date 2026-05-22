<?php

namespace App\Models;

use App\Enums\Permission;
use Database\Factories\IssueShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IssueShare model — one record per (issue, user) pair, unique constraint at DB level.
 *
 * @see SPEC §4.5 / ADR-007
 */
#[Fillable(['issue_id', 'user_id', 'permission'])]
class IssueShare extends Model
{
    /** @use HasFactory<IssueShareFactory> */
    use HasFactory;

    /**
     * No updated_at column in the issue_shares table per SPEC §4.5.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permission' => Permission::class,
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** The issue being shared. */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** The user this issue is shared with. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
