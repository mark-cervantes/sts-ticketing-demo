<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation for POST /api/issues/{issue}/shares.
 *
 * Rules:
 *   - email: required, valid email, not self (SPEC §4.5 business rule)
 *   - permission: required, must be a valid Permission enum value
 *
 * Note: email existence against users table is validated in the controller
 * to return a 422 (not 404) — avoids leaking whether an email exists.
 *
 * @see task 04.01.00 / SPEC §4.5
 */
class StoreShareRequest extends FormRequest
{
    /**
     * Authorization is delegated to IssuePolicy::share() via the controller.
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
            'email' => ['required', 'email', 'not_in:'.$this->user()?->email],
            'permission' => ['required', new Enum(Permission::class)],
        ];
    }

    /**
     * Custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.not_in' => 'You cannot share an issue with yourself.',
        ];
    }
}
