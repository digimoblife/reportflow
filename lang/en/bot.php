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
            "How it works: just tell me what you worked on, for example \"Fixed the login bug on 9Club today\". I'll note it down and file it.\n\nAvailable commands:\n/start - begin and create your first project\n/help - this guide\n\nThe other commands (tasks, undo, report, and so on) will arrive step by step.",
            "Quick guide: write what you did today and I'll log it.\n\nAvailable commands:\n/start - begin and create your first project\n/help - this guide\n\nMore commands are coming step by step.",
        ],
    ],

    'worklog' => [
        'ack' => [
            '⏳ Noting it down…',
            '⏳ One moment, writing it up…',
            '⏳ Logging it…',
        ],
        'recorded_dummy' => [
            'Got it, your note is saved in the archive. Automatic interpretation comes in a later version.',
            "Saved. I haven't linked it to a task yet, that comes later.",
            'Done, your note is safely archived. Task matching is coming soon.',
        ],
        'failed' => [
            "Oops, I couldn't process this note. Don't worry, the message is saved and can be retried later.",
            'This note failed to process. The original message is safely stored and can be reprocessed later.',
        ],
        'edit_saved_notice' => [
            'Your edit is saved in the archive. Entries already created from the earlier version are not changed.',
            'Edit saved in the archive. Anything already recorded stays as it was.',
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

    'callback' => [
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
