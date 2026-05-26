<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Validation for PUT/PATCH /api/statuses/{status}.
 *
 * @see task 08.01 / SRS §FR-02
 */
class UpdateStatusRequest extends FormRequest
{
    /**
     * Any authenticated user may update a status.
     * Auth is enforced by the 'auth' middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim name before validation runs.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * Validation rules.
     *
     * Case-insensitive uniqueness excluding the current record (by route-bound id).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statusId = $this->route('status')?->id;

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($statusId): void {
                    $exists = DB::table('statuses')
                        ->whereRaw('LOWER(name) = ?', [strtolower((string) $value)])
                        ->when($statusId !== null, fn ($q) => $q->where('id', '!=', $statusId))
                        ->exists();

                    if ($exists) {
                        $fail('The '.$attribute.' has already been taken.');
                    }
                },
            ],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
