<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single-row configuration table for AI summary settings.
 *
 * The api_key column is always encrypted at rest via Laravel's 'encrypted' cast.
 * It is hidden from JSON serialisation — never expose raw keys to the frontend.
 *
 * Use AiSetting::current() to obtain the singleton row, which is created from
 * .env defaults on first access.
 *
 * @property int $id
 * @property string $provider 'rules' | 'openrouter' | 'ollama' | 'custom'
 * @property string|null $base_url
 * @property string|null $api_key (encrypted)
 * @property string|null $model
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string|null $effective_base_url
 * @property-read string $effective_driver
 * @property-read User|null $updatedBy
 */
class AiSetting extends Model
{
    /**
     * @var array<int,string>
     */
    protected $fillable = [
        'provider',
        'base_url',
        'api_key',
        'model',
        'updated_by',
    ];

    /**
     * api_key is always encrypted at rest, never returned in JSON.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'api_key' => 'encrypted',
    ];

    /**
     * Ensure api_key never leaks into API responses.
     *
     * @var array<int,string>
     */
    protected $hidden = ['api_key'];

    // -------------------------------------------------------------------------
    // Singleton accessor
    // -------------------------------------------------------------------------

    /**
     * Return the singleton AI settings row.
     *
     * On first call (no row exists), creates from .env defaults so the app
     * starts with sane values without manual seeding. After that, the DB row
     * is authoritative — .env values are ignored.
     */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'provider' => env('SUMMARY_PROVIDER', 'rules'),
            'base_url' => env('SUMMARY_BASE_URL'),
            'api_key' => env('SUMMARY_API_KEY'),
            'model' => env('SUMMARY_MODEL'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Computed attributes
    // -------------------------------------------------------------------------

    /**
     * Resolve the effective base URL for the configured provider.
     *
     * openrouter defaults to https://openrouter.ai/api/v1 when none stored.
     * ollama defaults to http://localhost:11434/v1 when none stored.
     * custom and rules use whatever is stored (may be null).
     */
    public function getEffectiveBaseUrlAttribute(): ?string
    {
        if ($this->base_url !== null && $this->base_url !== '') {
            return $this->base_url;
        }

        return match ($this->provider) {
            'openrouter' => 'https://openrouter.ai/api/v1',
            'ollama' => 'http://localhost:11434/v1',
            default => null,
        };
    }

    /**
     * Map provider to an internal SummaryManager driver name.
     *
     * 'rules' maps to the deterministic driver.
     * Everything else maps to 'llm' (all use the same OpenAI-compatible driver).
     */
    public function getEffectiveDriverAttribute(): string
    {
        return $this->provider === 'rules' ? 'rules' : 'llm';
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The user who last updated these settings.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
