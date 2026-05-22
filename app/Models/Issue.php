<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\Status;
use App\Enums\SummaryStatus;
use App\Enums\Visibility;
use Carbon\CarbonImmutable;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Issue model — core entity of the ticketing system.
 *
 * @see SPEC §4.2 / ADR-005 / BR-01 / BR-03 / FR-02
 */
#[Fillable([
    'user_id',
    'title',
    'description',
    'priority',
    'category_id',
    'status',
    'visibility',
    'summary',
    'suggested_next_action',
    'summary_status',
    'needs_attention',
    'deadline_at',
])]
class Issue extends Model
{
    /** @use HasFactory<IssueFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => Priority::class,
            'status' => Status::class,
            'visibility' => Visibility::class,
            'summary_status' => SummaryStatus::class,
            'deadline_at' => 'immutable_datetime',
            'needs_attention' => 'boolean',
        ];
    }

    /**
     * Bootstrap model events.
     *
     * Sets needs_attention automatically on every create/update (BR-03).
     */
    protected static function booted(): void
    {
        static::saving(function (Issue $issue): void {
            $issue->needs_attention = self::computeNeedsAttention(
                $issue->priority,
                $issue->deadline_at,
            );
        });
    }

    /**
     * Compute whether this issue needs attention based on priority and deadline.
     *
     * Pure static method — testable without DB.
     *
     * @see ADR-005 / BR-03
     */
    public static function computeNeedsAttention(Priority $priority, ?CarbonImmutable $deadlineAt): bool
    {
        if ($priority->needsAttention()) {
            return true;
        }

        if ($deadlineAt !== null) {
            $threshold = config('issues.attention_threshold_minutes', 60);

            return $deadlineAt->lte(now()->addMinutes($threshold));
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** The user who owns this issue. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The category this issue belongs to. */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** Comments posted on this issue. */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** Share records for this issue. */
    public function shares(): HasMany
    {
        return $this->hasMany(IssueShare::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope to filter by status enum value.
     *
     * Silently ignores invalid status strings (tryFrom returns null → no-op).
     */
    public function scopeFilterByStatus(Builder $query, ?string $value): Builder
    {
        if ($value === null) {
            return $query;
        }

        $status = Status::tryFrom($value);

        if ($status === null) {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * Scope to filter by priority enum value.
     *
     * Silently ignores invalid priority strings (tryFrom returns null → no-op).
     */
    public function scopeFilterByPriority(Builder $query, ?string $value): Builder
    {
        if ($value === null) {
            return $query;
        }

        $priority = Priority::tryFrom($value);

        if ($priority === null) {
            return $query;
        }

        return $query->where('priority', $priority);
    }

    /**
     * Scope to filter by category slug.
     *
     * Resolves slug → category_id. Silently ignores unknown slugs.
     */
    public function scopeFilterByCategory(Builder $query, ?string $slug): Builder
    {
        if ($slug === null) {
            return $query;
        }

        $categoryId = Category::where('slug', $slug)->value('id');

        if ($categoryId === null) {
            return $query;
        }

        return $query->where('category_id', $categoryId);
    }

    /**
     * Scope to issues accessible by the given user.
     *
     * Returns issues where the user is the owner, OR has a share row,
     * OR the issue is public. Uses distinct() to prevent duplicates when
     * both owner and shared conditions match the same row.
     *
     * @see SRS §8.2 I-18 / ADR-004 §Access Resolution
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->distinct()->where(function (Builder $q) use ($user): void {
            $q->where('user_id', $user->id)
                ->orWhereHas('shares', function (Builder $sq) use ($user): void {
                    $sq->where('user_id', $user->id);
                })
                ->orWhere('visibility', Visibility::Public);
        });
    }
}
