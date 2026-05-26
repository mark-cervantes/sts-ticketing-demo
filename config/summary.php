<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Summary Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default driver used for AI summary generation.
    | Supported: "llm", "rules"
    |
    | If the "llm" driver is selected but no LLM_API_KEY is configured, the
    | SummaryManager will automatically fall back to the "rules" driver.
    |
    */
    'default' => env('SUMMARY_DRIVER', 'llm'),

    'drivers' => [

        'llm' => [
            'base_url' => env('LLM_BASE_URL'),
            'api_key' => env('LLM_API_KEY'),
            'model' => env('LLM_MODEL'),
            'timeout' => (int) env('LLM_TIMEOUT', 30),
        ],

        'rules' => [],

    ],

];
