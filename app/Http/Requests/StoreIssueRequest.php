<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/issues.
 *
 * @see task 02.01.00 / SRS §FR-02
 */
class StoreIssueRequest extends FormRequest
{
    /**
     * Any authenticated user may create an issue.
     * Authorization is delegated to IssuePolicy via the controller.
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority' => ['required', 'string', Rule::enum(Priority::class)],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'visibility' => ['sometimes', 'nullable', 'string', Rule::enum(Visibility::class)],
            'deadline_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    /**
     * Trim string fields before validation.
     *
     * @return array<string, mixed>
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $this->merge(['title' => trim((string) $this->input('title'))]);
        }

        if ($this->has('description')) {
            $this->merge(['description' => trim((string) $this->input('description'))]);
        }
    }
}
