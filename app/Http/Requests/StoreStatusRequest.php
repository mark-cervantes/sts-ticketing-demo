<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Validation for POST /api/statuses.
 *
 * @see task 08.01 / SRS §FR-02
 */
class StoreStatusRequest extends FormRequest
{
    /**
     * Any authenticated user may create a status.
     * Auth is enforced by the 'auth' middleware on the route.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim name before validation runs (so uniqueness check operates on clean value).
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
     * Case-insensitive uniqueness: same pattern as StoreCategoryRequest.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $exists = DB::table('statuses')
                        ->whereRaw('LOWER(name) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('The '.$attribute.' has already been taken.');
                    }
                },
            ],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}
