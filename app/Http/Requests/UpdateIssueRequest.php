<?php

namespace App\Http\Requests;

use App\Enums\Priority;
use App\Enums\Status;
use App\Enums\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for PATCH /api/issues/{issue}.
 *
 * All issue fields are optional. `updated_at` is required for optimistic locking.
 *
 * @see task 02.01.00 / SRS §FR-02
 */
class UpdateIssueRequest extends FormRequest
{
    /**
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
            'updated_at' => ['required', 'date'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'nullable', 'string', Rule::enum(Priority::class)],
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(Status::class)],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
            'visibility' => ['sometimes', 'nullable', 'string', Rule::enum(Visibility::class)],
            'deadline_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ];
    }

    /**
     * Trim string fields before validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('title') && $this->input('title') !== null) {
            $this->merge(['title' => trim((string) $this->input('title'))]);
        }

        if ($this->has('description') && $this->input('description') !== null) {
            $this->merge(['description' => trim((string) $this->input('description'))]);
        }
    }
}
