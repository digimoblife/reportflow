<?php

/*
| Operations (M9): acceptance targets that the Health page measures against (PRD §73, §76), alert thresholds and
| backup expectations. Targets for extraction, project, task matching and date accuracy are measured offline with
| `php artisan eval:run`; the ones below can be measured from live data.
*/
return [
    'targets' => [
        'correction_rate_max' => 0.15,        // PRD §76
        'worklog_p95_seconds_max' => 10,      // PRD §73 response time
        'report_success_min' => 0.95,         // PRD §73 report generation
        'pdf_success_min' => 0.99,            // PRD §73 PDF generation
        'ai_failure_rate_max' => 0.05,        // engineering guard, not in the PRD
    ],

    // Telegram user id that receives operational alerts; empty = the first registered user.
    'admin_telegram_user_id' => env('ADMIN_TELEGRAM_USER_ID'),

    'alerts' => [
        'disk_percent' => 80,
        'queue_backlog' => 50,
        'failed_jobs_new' => 1,
        'backup_max_age_hours' => 26,
        'restore_max_age_days' => 35,
        'heartbeat_max_age_minutes' => 10,
    ],

    // 'real' talks to PostgreSQL, Redis, Gotenberg and the disk; 'fake' is for tests only and refused in production.
    'probe' => env('OPS_PROBE', 'real'),

    // Where the backup service writes its status (read by ops:check).
    'backup_status_file' => env('BACKUP_STATUS_FILE', storage_path('app/backup-status.json')),
];
