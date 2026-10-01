<?php

/*
| AI settings (PRD §50–§54). Tests always use the fake provider and locked, unresolvable endpoints
| (tests/bootstrap.php). "deepseek" needs DEEPSEEK_API_KEY; model IDs verified against
| api-docs.deepseek.com (2026-09-30): deepseek-flash, deepseek-v4-pro; JSON mode = response_format json_object.
*/
return [
    // Default is the real provider so a deployment can never fall back to the fake by omission (production
    // refuses "fake" at boot). Dev sets AI_PROVIDER=fake explicitly in .env; tests force it in tests/bootstrap.php.
    'provider' => env('AI_PROVIDER', 'deepseek'),

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'model' => env('AI_MODEL', 'deepseek-flash'),
        'connect_timeout' => 5,
        'timeout' => 25,
        'max_tokens' => 2048,
    ],

    // PRD §13 initial values; retuned from the evaluation dataset (skill prompt-eval).
    // Price per million tokens by model, to show an estimated cost on the Health page (PRD §79). Left empty on purpose:
    // fill it in from your provider's current price list, e.g. 'deepseek-flash' => ['input_per_million' => 0.0, 'output_per_million' => 0.0].
    'pricing' => [],

    'confidence' => [
        'high' => 0.90,
        'medium' => 0.70,
    ],

    'report_instruction' => [
        'prompt' => 'report_instruction@v1',
    ],

    'report_section' => [
        'prompt' => 'report_section@v1',
    ],

    'correction' => [
        'prompt' => 'worklog_correction@v1',
    ],

    'extraction' => [
        'prompt' => 'worklog_extraction@v2',
        // Task lookback for Completed tasks in the candidate list (PRD §12).
        'completed_lookback_days' => 30,
        // A date older than this needs confirmation (PRD §54 step 6).
        'backdate_confirm_days' => 30,
        // A task with no activity for this long makes an "update" less trustworthy (PRD §13 signals).
        'stale_task_days' => 90,
        'recent_activities' => 3,
    ],
];
