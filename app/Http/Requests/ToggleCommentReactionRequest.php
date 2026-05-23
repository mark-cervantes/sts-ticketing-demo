<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the emoji field for reaction toggle.
 *
 * Only the curated set from SPEC is accepted.
 */
class ToggleCommentReactionRequest extends FormRequest
{
    /** Allowed emoji set per task spec. */
    public const ALLOWED_EMOJIS = ['👍', '👎', '😄', '🎉', '😕', '❤️', '🚀', '👀'];

    public function authorize(): bool
    {
        return true; // Auth checked by middleware; additional authorization in controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', 'in:'.implode(',', self::ALLOWED_EMOJIS)],
        ];
    }
}
