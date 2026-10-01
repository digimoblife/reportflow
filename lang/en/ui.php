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
        'worklog' => [
            'nav' => 'Log Work',
            'title' => 'Log Work',
            'intro' => 'Write what you worked on, several items at once is fine. I match them to your existing tasks.',
            'placeholder' => 'Example: Harbor Portal: fixed the invoice bug. Then met Rina about the report.',
            'submit' => 'Send',
            'recent' => 'Recent notes',
            'empty' => 'No notes yet.',
            'channel' => ['telegram' => 'Telegram', 'dashboard' => 'Dashboard'],
            'states' => [
                'received' => 'Waiting to be processed',
                'processing' => 'Processing',
                'processed' => 'Done',
                'needs_clarification' => 'Needs your answer',
                'failed' => 'Failed to process',
            ],
            'item_states' => [
                'pending' => 'Waiting for your answer in the Inbox',
                'skipped' => 'Skipped',
                'rejected' => 'Rejected: does not match the archive',
                'undone' => 'Cancelled',
            ],
            'actions' => [
                'undo' => 'Undo',
                'undo_all' => 'Undo all',
                'move' => 'Move Task',
                'status' => 'Change Status',
                'project' => 'Change Project',
                'apply' => 'Apply',
                'cancel' => 'Cancel',
            ],
            'pick' => [
                'move' => 'Move this note to task:',
                'status' => 'Change the status of ":task" to:',
                'project' => 'Move this new task to project:',
                'new_task' => 'New task',
                'choose' => 'Choose…',
            ],
            'notices' => [
                'queued' => 'Note received, processing it now.',
                'duplicate' => 'This note was already received.',
                'empty' => 'Write the note first.',
                'invalid' => 'The note is too long. Split it into parts.',
                'redaction_failed' => 'The note could not be checked safely, so it was not saved. Please resend it, in parts if needed.',
                'secrets' => ':count part(s) containing a key or password were not saved.',
                'undone' => 'Note cancelled.',
                'undone_partial' => 'Note cancelled, but the task changed since, so its status was not restored.',
                'done' => 'Updated.',
                'stale' => 'The task just changed elsewhere. Reload and try again.',
                'not_possible' => 'That change is not possible.',
            ],
        ],
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
