<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a stateless chat message request.
 *
 * The history array contains the client-side session messages.
 * Nothing is persisted on this endpoint — purely ephemeral.
 */
class StoreChatMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
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
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes', 'array'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.content' => ['required', 'string'],
        ];
    }
}
