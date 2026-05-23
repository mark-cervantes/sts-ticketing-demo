<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation for PATCH /api/shares/{share}.
 *
 * Rules:
 *   - permission: required, must be a valid Permission enum value
 *
 * @see task 04.01.00 / SPEC §4.5
 */
class UpdateShareRequest extends FormRequest
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
            'permission' => ['required', new Enum(Permission::class)],
        ];
    }
}
