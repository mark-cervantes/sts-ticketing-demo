<?php

return [
    'attention_threshold_minutes' => env('ATTENTION_THRESHOLD_MINUTES', 60),

    'auto_archive' => [
        'enabled' => env('AUTO_ARCHIVE_ENABLED', true),
        'statuses' => ['resolved'],
        'after_days' => (int) env('AUTO_ARCHIVE_DAYS', 7),
    ],
];
