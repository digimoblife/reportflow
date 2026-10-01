<?php

/*
| Report engine settings (PRD §36–§46). The default generic report has the seven sections of PRD §38; a section is
| `narrative` when the AI writes an introduction for it (always from that section's own data, validated by the
| backend), otherwise it is only the deterministic facts. Titles and fixed wording live in lang/{id,en}/report.php:
| reports are formal and neutral, never the bot persona (CLAUDE.md rule 8).
*/
return [
    'sections' => [
        ['key' => 'overview', 'narrative' => true],
        ['key' => 'completed', 'narrative' => false],
        ['key' => 'detailed', 'narrative' => true],
        ['key' => 'ongoing', 'narrative' => true],
        ['key' => 'cross_month', 'narrative' => false],
        ['key' => 'incidents', 'narrative' => false],
        ['key' => 'summary', 'narrative' => true],
    ],

    'blade_view' => 'reports.templates.generic',

    // A generation lock older than this is considered abandoned (a worker that died mid-report).
    'lock_seconds' => 300,

    // "Wait until finished" gives up after this long and generates without the pending entries.
    'wait_minutes' => 10,

    // Signed download links.
    'download_ttl_minutes' => 10,

    // Gotenberg (PDF). "fake" in tests (tests/bootstrap.php); production refuses it.
    'pdf' => [
        'renderer' => env('PDF_RENDERER', 'gotenberg'),
        'url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
        'timeout' => 60,
    ],
];
