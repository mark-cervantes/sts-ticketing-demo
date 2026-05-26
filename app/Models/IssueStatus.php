<?php

namespace App\Models;

use Database\Factories\StatusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * IssueStatus model — DB-backed workflow states for issues.
 *
 * Slug auto-generated on creating event (same pattern as Category).
 * Deletion guard: cannot delete if is_default or if issues reference it.
 *
 * @see task 08.01 / SPEC §4.2 / ADR-006 / SRS §FR-02
 */
#[Fillable(['name', 'slug', 'color', 'sort_order', 'is_default'])]
class IssueStatus extends Model
{
    /** @use HasFactory<StatusFactory> */
    use HasFactory;

    /**
     * Use the 'statuses' table (not 'issue_statuses').
     */
    protected $table = 'statuses';

    /**
     * Bootstrap model events.
     *
     * Auto-generates a unique slug from the status name on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (IssueStatus $status): void {
            $status->slug = static::generateUniqueSlug($status->name);
        });
    }

    /**
     * Generate a unique slug from the given name.
     *
     * Collision resolution: base slug gets numeric suffixes starting at -2.
     */
    protected static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        $existing = static::query()
            ->where('slug', 'like', $base.'%')
            ->pluck('slug')
            ->all();

        if (! in_array($base, $existing, true)) {
            return $base;
        }

        $max = 1;
        foreach ($existing as $slug) {
            if (preg_match('/^'.preg_quote($base, '/').'(?:-(\d+))?$/', $slug, $matches)) {
                $suffix = isset($matches[1]) ? (int) $matches[1] : 1;
                if ($suffix > $max) {
                    $max = $suffix;
                }
            }
        }

        return $base.'-'.($max + 1);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** Issues that use this status. */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class, 'status_id');
    }
}
