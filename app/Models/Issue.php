<?php

namespace App\Models;

use App\Enums\Priority;
use App\Enums\Status;
use App\Enums\SummaryStatus;
use App\Enums\Visibility;
use Carbon\CarbonImmutable;
use Database\Factories\IssueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
}
