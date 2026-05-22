<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/issues/{issue}/comments.
 *
 * @see task 02.02.00 / SRS §FR-07
 */
class StoreCommentRequest extends FormRequest
{
    /**
     * Authorization is delegated to CommentPolicy via the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1'],
        ];
    }

    /**
     * Trim body before validation so spaces-only input fails min:1.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('body')) {
            $this->merge(['body' => trim((string) $this->input('body'))]);
        }
    }
}
