<?php

return [
    'openrouter' => [
        'label' => 'OpenRouter',
        'description' => 'Access 300+ AI models via OpenRouter',
        'provider' => 'openrouter',
        'base_url' => 'https://openrouter.ai/api/v1',
        'model' => 'google/gemini-2.5-flash',
        'api_key' => env('AI_PRESET_OPENROUTER_KEY'),
    ],
    'ollama-cloud' => [
        'label' => 'Ollama Cloud',
        'description' => 'Ollama Cloud hosted models',
        'provider' => 'custom',
        'base_url' => env('AI_PRESET_OLLAMA_URL', 'https://ollama.com/api'),
        'model' => 'gemma3:4b',
        'api_key' => env('AI_PRESET_OLLAMA_KEY'),
    ],
];
