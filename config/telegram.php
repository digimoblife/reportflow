<?php

/*
| Telegram Bot API settings (PRD §18, §56). Secrets come from .env only:
| TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_SECRET_TOKEN, TELEGRAM_WEBHOOK_PATH.
| TELEGRAM_CLIENT: "http" (real Bot API) or "fake" (tests only; refused in production).
*/
return [
    'client' => env('TELEGRAM_CLIENT', 'http'),

    'token' => env('TELEGRAM_BOT_TOKEN'),

    // Public bot username (not a secret) for the dashboard's Telegram Login Widget; empty = widget disabled.
    'bot_username' => env('TELEGRAM_BOT_USERNAME'),

    // Sent back by Telegram in X-Telegram-Bot-Api-Secret-Token; A-Z a-z 0-9 _ - (1..256).
    'secret_token' => env('TELEGRAM_BOT_SECRET_TOKEN'),

    // Unguessable path segment of the webhook URL; at least 32 characters.
    'webhook_path' => env('TELEGRAM_WEBHOOK_PATH'),

    'api_base' => 'https://api.telegram.org',

    'connect_timeout' => 3,
    'timeout' => 5,

    // Uploading a file (a report) takes longer than a JSON call.
    'upload_timeout' => 30,

    // Telegram rejects messages longer than this.
    'max_message_length' => 4096,

    // The only update types the bot subscribes to.
    'allowed_updates' => ['message', 'edited_message', 'callback_query'],

    // Log an unregistered sender's numeric id at most once per this many seconds per id.
    'unregistered_log_ttl' => 3600,
];
