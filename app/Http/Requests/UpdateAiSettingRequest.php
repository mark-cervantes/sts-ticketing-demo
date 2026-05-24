<?php

namespace App\Http\Requests;

use App\Models\AiSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for PUT /api/settings/ai.
 *
 * api_key handling:
 *  - If sent as empty string or null → keep the existing key (no accidental clearing).
 *  - If sent as a non-empty string → update.
 *  - If not present in the request → leave untouched.
 *
 * openrouter provider must have an api_key (in request or already stored in DB).
 *
 * @see Task 08.01
 */
class UpdateAiSettingRequest extends FormRequest
{
    /**
     * Only authenticated users may update AI settings.
     * Admin-level gate is handled at the middleware/policy level.
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
        // When a preset is provided, provider and other fields are optional
        // (they are resolved server-side from the preset config).
        if ($this->has('preset')) {
            return [
                'preset' => ['required', 'string', 'max:100'],
            ];
        }

        return [
            'provider' => ['required', 'string', Rule::in(['rules', 'openrouter', 'ollama', 'custom'])],
            'base_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Additional validation: openrouter requires an api_key to be set — either
     * passed in this request OR already stored in the database.
     * Skip this check when a preset is used (preset has its own key validation).
     *
     * @return array<string, string>
     */
    public function withValidator(Validator $validator): void
    {
        // Preset path has separate validation in the controller.
        if ($this->has('preset')) {
            return;
        }

        $validator->after(function (Validator $v): void {
            $provider = $this->input('provider');

            if ($provider !== 'openrouter') {
                return;
            }

            $incomingKey = $this->input('api_key');
            $hasIncomingKey = is_string($incomingKey) && $incomingKey !== '';

            if ($hasIncomingKey) {
                return;
            }

            // No key in request — check DB.
            $existing = AiSetting::first();
            $existingKey = $existing?->api_key;

            if (empty($existingKey)) {
                $v->errors()->add('api_key', 'An API key is required when using the openrouter provider.');
            }
        });
    }
}
