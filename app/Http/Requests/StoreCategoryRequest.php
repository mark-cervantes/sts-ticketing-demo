<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * Validation for POST /api/categories.
 *
 * @see task 02.03.00 / SRS §FR-08
 */
class StoreCategoryRequest extends FormRequest
{
    /**
     * Any authenticated user may create a category.
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
     * Case-insensitive uniqueness: the DB UNIQUE index is case-sensitive (PostgreSQL),
     * so we check LOWER(name) via a raw query at the application layer — this catches
     * "Bug Reports" vs "bug reports" vs "BUG REPORTS" as duplicates.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $exists = DB::table('categories')
                        ->whereRaw('LOWER(name) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('The '.$attribute.' has already been taken.');
                    }
                },
            ],
        ];
    }
}
