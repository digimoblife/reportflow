<?php

/*
| Pak Carik bot messages (persona) — English: relaxed and friendly, no Javanese.
| Mirror of lang/id/bot.php: same keys, same placeholders in every variant.
*/

return [
    'onboarding' => [
        'welcome' => [
            "Hello! I'm Pak Carik, your work log keeper. Let's start with your first project. What's it called?",
            "Hi, I'm Pak Carik. I keep track of your work. Let's begin with your first project: what's its name?",
            "Nice to meet you, I'm Pak Carik. I'll be keeping your work log. What should we call your first project?",
        ],
        'already_set_up' => [
            "You already have :count project(s). Just tell me what you've been working on and I'll note it down.",
            'All set (:count project(s)). Go ahead and tell me what you worked on.',
        ],
        'project_created' => [
            'Project ":project" is ready. Now tell me what you worked on.',
            'Done, project ":project" is in the archive. Go ahead and tell me about your work.',
        ],
        'project_exists' => [
            'Project ":project" already exists. Feel free to tell me about your work.',
            'The name ":project" is taken, so I will use the existing project. Go ahead.',
        ],
        'name_invalid' => [
            "That project name can't be used. Use 1 to 80 characters.",
            'A project name must be 1 to 80 characters and not empty. Please try again.',
        ],
    ],

    'help' => [
        'guide' => [
            "How it works: just tell me what you worked on, for example \"Fixed the login bug on 9Club today\". I'll note it down and file it.\n\nAvailable commands:\n/start - begin and create your first project\n/projects - list projects\n/project - one project in detail\n/tasks - tasks in progress\n/task - one task in detail\n/undo - cancel the last note\n/inbox - notes that need a look\n/reminder - set the daily and monthly reminders\n/report - make the monthly report\n/review - review the draft report\n/reports - list reports\n/help - this guide",
            "Quick guide: write what you did today and I'll log it.\n\nAvailable commands:\n/start - begin and create your first project\n/projects - list projects\n/project - one project in detail\n/tasks - tasks in progress\n/task - one task in detail\n/undo - cancel the last note\n/inbox - notes that need a look\n/reminder - set the daily and monthly reminders\n/report - make the monthly report\n/review - review the draft report\n/reports - list reports\n/help - this guide",
        ],
    ],

    'worklog' => [
        'ack' => [
            '⏳ Noting it down…',
            '⏳ One moment, writing it up…',
            '⏳ Logging it…',
        ],
        'recorded' => [
            'Got it, noted.',
            'Done, it is in the archive.',
            "Noted. Here's what I recorded.",
        ],
        'nothing_recorded' => [
            "I read this, but there's no work in it to record.",
            'Nothing to record from this message.',
        ],
        'pending_notice' => [
            ':count note(s) are waiting for your answer below.',
            'I need you to confirm :count note(s) below.',
        ],
        'rejected_notice' => [
            "I couldn't save :count note(s) because they don't match the archive.",
            ':count note(s) were rejected: the details do not fit the archive.',
        ],
        'split_required' => [
            'This message has more than 5 notes, so nothing was saved yet. Please split it into several messages.',
            'Too many notes in one message (maximum 5). Nothing was saved; please resend in smaller messages.',
        ],
        'failed' => [
            "Oops, I couldn't process this note. Don't worry, the message is saved and can be retried later.",
            'This note failed to process. The original message is safely stored and can be reprocessed later.',
        ],
        'edit_saved_notice' => [
            'Your edit is saved. Entries already created from the earlier version are not changed. Reprocess with the new text?',
            'Edit saved. Anything already recorded stays as it was. Want me to reprocess it with the new text?',
        ],
        'attachment_ignored' => [
            "I can't read attachments yet, so I only noted the text.",
            'Only the text was noted. Attachments are not supported yet.',
        ],
    ],

    'security' => [
        'credential_detected' => [
            'Heads up: this message contains a password or secret key (:count part(s)). That part was not saved.',
            'This message contains a credential (:count part(s)). It was removed and not saved.',
        ],
        'redaction_error' => [
            "I couldn't check this message safely, so it was not saved and not processed. Please send it again, in shorter pieces if needed.",
            "I couldn't safely check this message. It was not saved or processed. Please resend it.",
        ],
    ],

    'commands' => [
        'unavailable' => [
            "The :command command isn't available yet. For now, just tell me about your work and I'll note it down.",
            "Sorry, :command isn't ready yet. Type /help to see what's available.",
        ],
        'unknown' => [
            "I don't know the :command command. Type /help for the list.",
            "I don't recognise :command. Try /help to see what's available.",
        ],
    ],

    'unsupported' => [
        'voice' => [
            "I can't read voice messages yet. Please write it down instead.",
            'Voice notes are not supported yet. Please send text.',
        ],
        'image' => [
            "I can't read images or screenshots yet. Please describe it in text.",
            'Images are not supported yet. Please send text.',
        ],
        'other' => [
            "I can't note this kind of message yet. Please send text.",
            'I can only note text messages for now. Please write it out.',
        ],
    ],

    'question' => [
        'match' => [
            'Is this note for the task ":task"?',
            'Quick check: is this for the task ":task"?',
        ],
        'project' => [
            'Which project is this note for?',
            "I'm not sure about the project. Which one?",
        ],
        'date' => [
            'The date is :date, more than 30 days ago. Record it on that date?',
            'This note is dated :date (over 30 days back). Use that date?',
        ],
        'cancelled' => [
            'Okay, I will not save that note.',
            'Understood, I skipped that note.',
        ],
    ],

    'undo' => [
        'done' => [
            'Done, that note is cancelled. The archive is back as it was.',
            'All set, I cancelled that note.',
        ],
        'some' => [
            ':count note(s) cancelled.',
            'I cancelled :count note(s).',
        ],
        'partial' => [
            "The note is removed, but the task changed since, so I didn't restore its status.",
            'Note cancelled. The task status is unchanged because the task changed after it.',
        ],
        'nothing' => [
            'There is nothing to undo yet.',
            'No recent note to undo.',
        ],
    ],

    'correction' => [
        'pick_task' => [
            'Which task should this note move to?',
            'Move this note to which task?',
        ],
        'pick_status' => [
            'Change the status of ":task" to what?',
            'What should the status of ":task" be?',
        ],
        'pick_project' => [
            'Which project should this new task move to?',
            'Move this new task to which project?',
        ],
        'done' => [
            'Updated.',
            'Done, changed.',
        ],
        'stale' => [
            'This task just changed. Please try again.',
            'The task was changed by something else. Try once more.',
        ],
        'not_possible' => [
            "That change isn't possible.",
            'That change is not allowed.',
        ],
        'reply_applied' => [
            'Okay, fixed. Here is the updated note.',
            "Done, I corrected it. Here's the result.",
        ],
        'reply_unchanged' => [
            'I read your reply but found nothing to change. The note stays as it was.',
            'Nothing changed. Try spelling out the fix, for example the task name or the status.',
        ],
        'project_new_only' => [
            'Changing the project only works for new tasks. For an existing task use Move task.',
            'Existing tasks cannot change project; move the note with Move task instead.',
        ],
    ],

    'list' => [
        'projects_title' => [
            'Your projects (:count):',
            'Here are your projects (:count):',
        ],
        'projects_empty' => [
            'No projects yet. Type /start to create the first one.',
            'Nothing here yet. Type /start first.',
        ],
        'project_not_found' => [
            'No project with that name. Try /projects to see the list.',
            "I can't find that project. See the full list at /projects.",
        ],
        'project_pick' => [
            'Several projects match. Pick one:',
            'More than one match. Please choose:',
        ],
        'tasks_title' => [
            'Active tasks (:count), page :page/:pages:',
            ':count active task(s), page :page/:pages:',
        ],
        'tasks_empty' => [
            'No active tasks yet.',
            'Nothing is in progress right now.',
        ],
        'task_usage' => [
            'Give a number or a word from the title, for example /task 12 or /task invoice.',
            'Name the task by number or a title word, e.g. /task 12 or /task invoice.',
        ],
        'task_not_found' => [
            'No matching task. Try /tasks to see the list.',
            "I can't find that task. See the list at /tasks.",
        ],
        'task_pick' => [
            'Several tasks match. Pick one:',
            'More than one match. Please choose:',
        ],
        'inbox_title' => [
            'Notes that need a look (:count):',
            ':count note(s) still need your attention:',
        ],
        'inbox_empty' => [
            'All clear, nothing is held up.',
            'Inbox is clean, nothing to check.',
        ],
    ],

    'reprocess' => [
        'started' => [
            'Got it, reprocessing that note.',
            "On it, I'll process that note again.",
        ],
        'busy' => [
            'That note is already being processed.',
            'That note is in progress, give it a moment.',
        ],
        'superseded' => [
            'This question was replaced because the note is being reprocessed.',
            'The note is being reprocessed, so this old question no longer applies.',
        ],
        'kept' => [
            'Okay, the earlier note stays as it was.',
            'Fine, nothing was changed.',
        ],
    ],

    'reminder' => [
        'daily' => [
            'Hi! No work notes yet today. Did you work on anything?',
            'Quick nudge: nothing is logged for today yet. Anything to note down?',
        ],
        'add_prompt' => [
            "Go ahead and just write what you did here, I'll log it.",
            'Sure, type what you worked on and I will note it down.',
        ],
        'none_done' => [
            'Okay, no more reminders today.',
            'Fine, I will close today\'s reminder.',
        ],
        'snoozed' => [
            "Okay, I'll remind you again in an hour.",
            'Sure, one more reminder in an hour.',
        ],
        'snooze_limit' => [
            "That's three snoozes, so I'm closing today's reminder.",
            'Snoozed three times already, so today\'s reminder is closed.',
        ],
        'status' => [
            "Reminder status:\n\nDaily: :state — :time (:days)\nMonthly: :mstate — :mtime (last day of the month)",
            "Your reminders right now:\n\nDaily: :state — :time (:days)\nMonthly: :mstate — :mtime (last day of the month)",
        ],
        'on_done' => [
            'Okay, reminders are back on.',
            'Done, reminders are active again.',
        ],
        'off_done' => [
            'Okay, reminders are off. Turn them back on with /reminder on.',
            'Done, no more reminders until you switch them on (/reminder on).',
        ],
        'daily_set' => [
            'Done, the daily reminder is now at :time.',
            'Okay, I will remind you on workdays at :time.',
        ],
        'daily_invalid' => [
            'I could not read that time. Write it like /reminder daily 18:00.',
            'That time format is off. A valid one: /reminder daily 17:30.',
        ],
        'usage' => [
            "Reminder commands:\n/reminder - status\n/reminder on - switch on\n/reminder off - switch off\n/reminder daily 18:00 - set the daily time\n/reminder monthly 09:00 - set the monthly reminder time",
            "How reminders work:\n/reminder - status\n/reminder on or off\n/reminder daily 18:00 - daily reminder time",
        ],
        'monthly_intro' => [
            ':month is almost over. Here is what your notes hold:',
            'End of :month. This is what you logged this month:',
        ],
        'monthly_ask' => [
            'Shall I prepare the report now?',
            'Want me to prepare the report now?',
        ],
        'monthly_started' => [
            'Okay, preparing the report.',
            'Sure, I am starting the report.',
        ],
        'monthly_review' => [
            'Okay, here are your active tasks to review first.',
            'Have a look at the tasks in progress below first.',
        ],
        'monthly_set' => [
            'Done, the monthly report reminder is now at :time on the last day of the month.',
            'Okay, I will remind you about the monthly report on the last day of the month at :time.',
        ],
        'monthly_on' => [
            'Monthly report reminder switched on.',
            'Done, the monthly report reminder is active.',
        ],
        'monthly_off' => [
            'Monthly report reminder switched off.',
            'Okay, the monthly report reminder is off.',
        ],
        'answered' => [
            'This reminder was already answered.',
            'Already answered earlier.',
        ],
    ],

    'report' => [
        'start_usage' => [
            'Write the month like /report 2026-09, or just /report for the month being reported.',
            'Month format: /report 2026-09. Without it I pick the right month.',
        ],
        'no_activity' => [
            'There are no notes in :month yet, so there is nothing to report.',
            ':month is still empty, no activity to report.',
        ],
        'pick_project' => [
            'Which project is the :month report for?',
            'Pick the project for the :month report:',
        ],
        'pending_entries' => [
            ':count note(s) are still being processed, so not everything is in the report yet. Wait, or generate without them?',
            ':count note(s) are still processing. Wait until they finish, or generate without them?',
        ],
        'already_approved' => [
            'The :period report is already approved. Make a new version?',
            'The :period report is final. Want a new version?',
        ],
        'outdated' => [
            'The :period report (:project) is approved, but :count change(s) were made to that period after its data was taken. Create a new version?',
            ':count change(s) were made to the period of the :period report (:project) after it was approved. Want a new version?',
        ],
        'dismissed' => [
            'Okay, nothing was changed.',
            'Fine, I left it as it was.',
        ],
        'review_intro' => [
            'The report draft is ready. Have a look before deciding.',
            "Here's the draft report. Check the summary, then choose what to do.",
        ],
        'review_approved' => [
            'This report has been approved.',
            'This report is final and approved.',
        ],
        'approved' => [
            "Report approved. I'll send the files in a moment, PDF and Markdown.",
            'Done, the report is approved. PDF and Markdown follow below.',
        ],
        'generating' => [
            "The report is being generated. I'll let you know when the draft is ready.",
            'The draft report is being put together, one moment.',
        ],
        'busy' => [
            'This report is already being generated. Wait until it finishes.',
            'A generation for this period is already running.',
        ],
        'stale' => [
            'The report changed after this message was sent. Here is the latest version.',
            'This message is out of date because the report was updated. Use the newest one.',
        ],
        'cancelled' => [
            'Report cancelled.',
            'Okay, I cancelled this report.',
        ],
        'not_possible' => [
            "That step isn't possible right now.",
            'Sorry, that cannot be done at the moment.',
        ],
        'edit_pick' => [
            'Which section do you want to change?',
            'Pick the section of the report to edit.',
        ],
        'edit_prompt' => [
            'Write your instruction for the ":section" section in one message, for example "make it shorter" or "add that the downtime lasted 25 minutes on the Tracking task".',
            'Send the instruction for the ":section" section in a single message. New facts are saved as activities first.',
        ],
        'instructed' => [
            'Applied as a new version. :count new fact(s) saved as activities. The latest draft follows.',
            'Instruction applied as a new version (:count new fact(s) saved). I will send the latest draft.',
        ],
        'instruction' => [
            'unmatched' => [
                'This fact does not match any task in the report: :facts. Name the task and send the instruction again.',
                'I could not find a task for: :facts. Mention the task and try again.',
            ],
            'redaction_failed' => [
                'That instruction could not be checked safely, so I did not process it.',
                'The instruction could not be checked safely. Try again with a shorter one.',
            ],
            'ai_failed' => [
                'The instruction cannot be processed right now, or its facts did not pass the checks. Nothing was changed.',
                'I could not process that instruction. Nothing was changed, please try again shortly.',
            ],
            'nothing_to_change' => [
                'This section only holds data, so an instruction without a new fact changes nothing.',
                'That section is pure data; without a new fact there is nothing to change.',
            ],
            'rewrite_failed' => [
                'That section could not be rewritten safely. Nothing was changed, please try again.',
                'The rewrite did not pass the checks, so nothing changed. Please try again.',
            ],
            'invalid' => [
                'The instruction is too short, too long, or the section is unknown. Try again.',
                'Invalid instruction. Write 3 to 1000 characters.',
            ],
        ],
        'files_pending' => [
            'The PDF is still being prepared and will follow.',
            'The PDF is not finished yet, it follows shortly.',
        ],
        'nothing_to_review' => [
            'No report is waiting for review.',
            'There is no draft report to review.',
        ],
        'none' => [
            'No reports yet.',
            'No reports yet. Start with /report.',
        ],
        'list_title' => [
            'Latest reports:',
            'Your recent reports:',
        ],
        'list_hint' => [
            'Type /review to review the newest draft.',
            'Use /review to open the draft that is waiting.',
        ],
    ],

    'ops' => [
        'alert' => [
            'database' => [
                'The database cannot be reached. Check the postgres container now.',
                'The database cannot be reached. Check the postgres container now.',
            ],
            'redis' => [
                'Redis cannot be reached, so queues and cache are affected.',
                'Redis cannot be reached, so queues and cache are affected.',
            ],
            'gotenberg' => [
                'Gotenberg (the PDF engine) is unreachable. Reports cannot get their PDF yet.',
                'Gotenberg (the PDF engine) is unreachable. Reports cannot get their PDF yet.',
            ],
            'disk' => [
                'Disk is :value% full (limit :limit%). Clean up or grow it before it fills.',
                'Disk is :value% full (limit :limit%). Clean up or grow it before it fills.',
            ],
            'queue_backlog' => [
                'Queues are piling up: :value jobs waiting (limit :limit). Check the workers.',
                'Queues are piling up: :value jobs waiting (limit :limit). Check the workers.',
            ],
            'failed_jobs' => [
                ':value queue job(s) failed in the last 24 hours. See failed_jobs.',
                ':value queue job(s) failed in the last 24 hours. See failed_jobs.',
            ],
            'worker_default' => [
                'The main queue worker does not look alive (heartbeat age :value min, limit :limit).',
                'The main queue worker does not look alive (heartbeat age :value min, limit :limit).',
            ],
            'worker_reports' => [
                'The reports worker does not look alive (heartbeat age :value min, limit :limit).',
                'The reports worker does not look alive (heartbeat age :value min, limit :limit).',
            ],
            'backup_failed' => [
                'The last backup FAILED. Check the backup service log.',
                'The last backup FAILED. Check the backup service log.',
            ],
            'backup_stale' => [
                'The last backup was :value hours ago (limit :limit h), or never succeeded.',
                'The last backup was :value hours ago (limit :limit h), or never succeeded.',
            ],
            'restore_overdue' => [
                'The last restore test was :value days ago (limit :limit). Run the restore test.',
                'The last restore test was :value days ago (limit :limit). Run the restore test.',
            ],
        ],
        'recovered' => [
            'database' => [
                'The database is reachable again.',
                'The database is reachable again.',
            ],
            'redis' => [
                'Redis has recovered.',
                'Redis has recovered.',
            ],
            'gotenberg' => [
                'Gotenberg is reachable again.',
                'Gotenberg is reachable again.',
            ],
            'disk' => [
                'Disk use is back down to :value%.',
                'Disk use is back down to :value%.',
            ],
            'queue_backlog' => [
                'Queues are back to normal.',
                'Queues are back to normal.',
            ],
            'failed_jobs' => [
                'No more failed jobs in the last 24 hours.',
                'No more failed jobs in the last 24 hours.',
            ],
            'worker_default' => [
                'The main queue worker is alive again.',
                'The main queue worker is alive again.',
            ],
            'worker_reports' => [
                'The reports worker is alive again.',
                'The reports worker is alive again.',
            ],
            'backup_failed' => [
                'Backups succeed again.',
                'Backups succeed again.',
            ],
            'backup_stale' => [
                'The backup is fresh again.',
                'The backup is fresh again.',
            ],
            'restore_overdue' => [
                'A restore test was done, thank you.',
                'A restore test was done, thank you.',
            ],
        ],
    ],

    'sync' => [
        'answered_via_dashboard' => [
            '✅ Already answered via the dashboard.',
            '✅ This question was answered on the dashboard.',
        ],
    ],

    'callback' => [
        'answered' => [
            'This question was already answered.',
            'Already answered earlier.',
        ],
        'expired' => [
            'This button is no longer valid.',
            'This button has expired.',
        ],
    ],

    'errors' => [
        'generic' => [
            'Something went wrong on my side. Please try again in a moment.',
            'I hit a snag on my side. Please try again shortly.',
        ],
    ],
];
