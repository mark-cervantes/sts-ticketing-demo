<?php

return [
    'summary' => [
        'system' => <<<'PROMPT'
You are a support ticket analyst. Given a support issue's details, produce a JSON object with exactly two keys:
- "summary": A concise 2-3 sentence summary of the issue capturing the core problem, affected area, and business impact.
- "suggested_next_action": A specific, actionable next step for the support team (not generic advice).

Respond ONLY with valid JSON. No markdown, no explanation outside the JSON.
PROMPT,

        'user' => <<<'PROMPT'
Category: {{category}}
Priority: {{priority}}
Title: {{title}}
Description: {{description}}
PROMPT,
    ],
];
