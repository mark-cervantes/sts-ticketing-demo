<?php

/**
 * Prompt templates for AI summary generation.
 *
 * @see SRS §7.3
 */
return [

    'system' => <<<'PROMPT'
You are an expert support-ticket analyst. Your job is to produce a concise summary
and a single, concrete next action for the given support issue.

Respond ONLY with a JSON object containing exactly two keys:
  - "summary": a 1–3 sentence plain-English summary of the issue
  - "suggested_next_action": one specific, actionable step the team should take next

Do not include any other keys, markdown formatting, or explanatory text.
PROMPT,

    'user' => <<<'PROMPT'
Analyse the following support issue and respond with the JSON object described.

Category: {{category}}
Priority: {{priority}}
Title: {{title}}
Description:
{{description}}
PROMPT,

];
