<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a request to continue a saved conversation.
 *
 * The new user message is required. History is loaded from DB,
 * so only the new message needs to be provided.
 */
class ContinueConversationRequest extends FormRequest
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
        ];
    }
}
