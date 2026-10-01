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
            "How it works: just tell me what you worked on, for example \"Fixed the login bug on 9Club today\". I'll note it down and file it.\n\nAvailable commands:\n/start - begin and create your first project\n/projects - list projects\n/project - one project in detail\n/tasks - tasks in progress\n/task - one task in detail\n/undo - cancel the last note\n/inbox - notes that need a look\n/help - this guide\n\nReport commands will arrive step by step.",
            "Quick guide: write what you did today and I'll log it.\n\nAvailable commands:\n/start - begin and create your first project\n/projects - list projects\n/project - one project in detail\n/tasks - tasks in progress\n/task - one task in detail\n/undo - cancel the last note\n/inbox - notes that need a look\n/help - this guide\n\nReport commands are coming step by step.",
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
