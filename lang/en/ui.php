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
    ],
];
