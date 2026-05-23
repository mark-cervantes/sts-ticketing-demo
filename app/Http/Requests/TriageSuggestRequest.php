<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/issues/triage-suggest.
 *
 * Any authenticated user may call this endpoint — authorization is just
 * "must be logged in", enforced by the route middleware.
 *
 * @see task 08.04
 */
class TriageSuggestRequest extends FormRequest
{
    /**
     * Any authenticated user may call this endpoint.
     * Route middleware handles the auth guard.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:500'],
            'description' => ['required', 'string', 'min:10', 'max:10000'],
        ];
    }
}
