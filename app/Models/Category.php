<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Category model — slug auto-generated on creating event with collision handling.
 *
 * @see SPEC §4.3 / FR-08
 */
#[Fillable(['name', 'slug'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    /**
     * No updated_at column in the categories table per SPEC §4.3.
     */
    public const UPDATED_AT = null;

    /**
     * Bootstrap model events.
     *
     * Auto-generates a unique slug from the category name on creation.
     */
    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            $category->slug = static::generateUniqueSlug($category->name);
        });
    }

    /**
     * Generate a unique slug from the given name.
     *
     * Collision resolution: base slug gets numeric suffixes starting at -2.
     * E.g.: "bug-reports", "bug-reports-2", "bug-reports-3", etc.
     */
    protected static function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        // Fetch all existing slugs that start with the base slug
        $existing = static::query()
            ->where('slug', 'like', $base.'%')
            ->pluck('slug')
            ->all();

        if (! in_array($base, $existing, true)) {
            return $base;
        }

        // Find the highest numeric suffix among collisions
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

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** Issues in this category. */
    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }
}
