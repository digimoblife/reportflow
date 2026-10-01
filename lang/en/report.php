<?php

/*
| Fixed report wording (PDF/Markdown): formal and neutral, never the Pak Carik persona (CLAUDE.md rule 8).
*/

return [
    'title' => ':project Monthly Report — :period',
    'title_custom' => ':project Activity Report — :period',
    'generic_template' => 'Generic Monthly Report',

    'sections' => [
        'overview' => 'Monthly Overview',
        'completed' => 'Completed Tasks',
        'detailed' => 'Detailed Activities',
        'ongoing' => 'Ongoing / Pending Tasks',
        'cross_month' => 'Cross-Month Activities',
        'incidents' => 'Incidents / Issues',
        'summary' => 'Monthly Summary',
    ],

    'labels' => [
        'project' => 'Project',
        'period' => 'Period',
        'activities' => 'Activities',
        'completed_tasks' => 'Completed tasks',
        'ongoing_tasks' => 'Ongoing tasks',
        'waiting_tasks' => 'Waiting tasks',
        'cross_month_tasks' => 'Cross-month tasks',
        'incidents' => 'Incidents / issues',
        'task' => 'Task',
        'status' => 'Status',
        'completed_on' => 'Completed on',
        'started' => 'Started',
        'last_activity' => 'Last activity',
        'date' => 'Date',
        'type' => 'Type',
        'description' => 'Description',
        'waiting_for' => 'Waiting for',
        'generated' => 'Generated',
        'none' => 'None.',
    ],

    'statuses' => [
        'draft' => 'Draft', 'open' => 'Open', 'in_progress' => 'In progress', 'waiting' => 'Waiting',
        'blocked' => 'Blocked', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
    ],

    'activity_types' => [
        'request' => 'Request', 'investigation' => 'Investigation', 'development' => 'Development', 'configuration' => 'Configuration',
        'bug_fix' => 'Bug fix', 'testing' => 'Testing', 'deployment' => 'Deployment', 'communication' => 'Communication',
        'research' => 'Research', 'documentation' => 'Documentation', 'milestone' => 'Milestone', 'blocker' => 'Blocker',
        'resolution' => 'Resolution', 'follow_up' => 'Follow-up', 'other' => 'Other',
    ],

    'waiting_reasons' => [
        'client' => 'client', 'vendor' => 'vendor', 'api' => 'API', 'launch' => 'launch', 'confirmation' => 'confirmation',
    ],

    // Sentences used when the AI narrative is rejected by the backend (traceability): numbers from the data only.
    'fallback' => [
        'overview' => 'During :period, :activities activities were recorded for :project: :completed tasks completed, :ongoing tasks ongoing, :waiting tasks waiting and :cross tasks carried over from earlier months.',
        'detailed' => 'The activities of this period are listed below, task by task.',
        'ongoing' => 'The following tasks were still ongoing or waiting at the end of the period.',
        'summary' => 'During :period, :activities activities were recorded; :completed tasks were completed and :open tasks remain ongoing or waiting.',
    ],
];
