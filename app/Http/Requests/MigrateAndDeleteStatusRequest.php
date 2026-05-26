<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for POST /api/statuses/{status}/migrate-and-delete.
 *
 * Accepts either:
 *  - { target_status_id: <int> }  → migrate all issues to that status, then delete
 *  - { delete_issues: true }       → delete all issues, then delete the status
 *
 * The route-bound {status} is the status being deleted, so target_status_id
 * must not reference it (no self-migration).
 *
 * @see task 08.02 / SRS §FR-02
 */
class MigrateAndDeleteStatusRequest extends FormRequest
{
    /**
     * Any authenticated user may call this endpoint.
     * Auth is enforced by the 'auth' middleware on the route.
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
        $deletingStatusId = $this->route('status')?->id;

        return [
            'target_status_id' => [
                'nullable',
                'integer',
                Rule::exists('statuses', 'id')->whereNot('id', $deletingStatusId),
            ],
            'delete_issues' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'target_status_id.exists' => 'The selected target status does not exist or is the same as the status being deleted.',
        ];
    }
}
