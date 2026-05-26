<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for POST /api/issues/{issue}/chat/tool-confirm.
 *
 * @see task 09.04 / IssueChatController::confirmTool()
 */
class StoreToolConfirmRequest extends FormRequest
{
    /**
     * Authorization is handled in the controller via IssuePolicy.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tool' => ['required', 'string'],
            'arguments' => ['required', 'array'],
        ];
    }
}
