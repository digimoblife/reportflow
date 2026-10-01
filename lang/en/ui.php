<?php

/*
| Bot interface labels (not persona messages): one value per key, no random variants.
*/

return [
    'labels' => [
        'project' => 'Project',
        'task' => 'Task',
        'activity' => 'Activity',
        'status' => 'Status',
        'new' => 'new',
        'reopened' => 'reopened',
        'explicit' => 'as you wrote',
    ],

    'activity_types' => [
        'request' => 'Request', 'investigation' => 'Investigation', 'development' => 'Development', 'configuration' => 'Configuration',
        'bug_fix' => 'Bug fix', 'testing' => 'Testing', 'deployment' => 'Deployment', 'communication' => 'Communication',
        'research' => 'Research', 'documentation' => 'Documentation', 'milestone' => 'Milestone', 'blocker' => 'Blocker',
        'resolution' => 'Resolution', 'follow_up' => 'Follow-up', 'other' => 'Other',
    ],

    'statuses' => [
        'draft' => 'Draft', 'open' => 'Open', 'in_progress' => 'In progress', 'waiting' => 'Waiting',
        'blocked' => 'Blocked', 'completed' => 'COMPLETED ✅', 'cancelled' => 'CANCELLED ✖️',
    ],

    'buttons' => [
        'undo' => '↩️ Undo',
        'undo_all' => '↩️ Undo all',
        'move' => '🔀 Move task',
        'status' => '✏️ Change status',
        'project' => '📁 Change project',
        'yes' => '✅ Yes',
        'new_task' => '🆕 New task',
        'back' => '⬅️ Back',
        'cancel' => '✖️ Cancel',
        'keep_date' => '✅ Use :date',
        'today' => '📅 Today',
        'redo' => '🔄 Reprocess',
        'keep' => 'Keep as is',
        'prev' => '⬅️ Previous',
        'next' => 'Next ➡️',
    ],

    'dashboard' => [
        'login' => [
            'title' => 'Sign in',
            'heading' => 'Sign in to ReportFlow',
            'intro' => 'Sign in with your Telegram account. Only registered accounts can sign in.',
            'not_configured' => 'Telegram login is not configured. Contact the system owner.',
        ],
    ],

    'lists' => [
        'active_tasks' => 'active tasks',
        'completed_tasks' => 'completed',
        'project' => 'Project',
        'status' => 'Status',
        'waiting_for' => 'Waiting on',
        'last_activity' => 'Last activity',
        'timeline' => 'History',
        'recent' => 'Recent notes',
        'none' => 'none yet',
        'inbox_failed' => 'failed',
        'inbox_pending' => 'awaiting your answer',
        'more' => '…and :count more',
    ],

    'events' => [
        'created' => 'created', 'status_changed' => 'status changed', 'title_changed' => 'title changed',
        'moved' => 'moved', 'merged' => 'merged', 'reopened' => 'reopened', 'undone' => 'undone',
    ],

    'waiting_reasons' => [
        'client' => 'client', 'vendor' => 'vendor', 'api' => 'API', 'launch' => 'launch', 'confirmation' => 'confirmation',
    ],
];
