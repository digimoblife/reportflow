<?php

/*
| Pin the test environment before Laravel boots.
|
| PHPUnit's <env force="true"> writes $_ENV and putenv() but not $_SERVER. Laravel's dotenv
| repository reads $_SERVER before $_ENV, so a process-level APP_ENV (docker-compose sets
| APP_ENV=local) silently won over phpunit.xml and the suite ran as "local". Writing all three
| stores here makes the values identical whichever one is read, in Docker, CI and local shells.
|
| Tests that need another environment or DEV_USER_* must prepare it themselves (see
| withAppEnvironment() in tests/Pest.php).
*/

require __DIR__.'/../vendor/autoload.php';

foreach ([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'reportflow_test',
    'DB_USERNAME' => 'reportflow_tester',
    'DEV_USER_EMAIL' => '',
    'DEV_USER_PASSWORD' => '',
    // phpunit.xml's non-forced <env> values lose to the process/.env values on Laravel's read path
    // (same $_SERVER issue as above), so the stores that must never be the real ones are pinned here too:
    // a test that flushes the cache or dispatches a job must not touch the developer's Redis/queue.
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null',
    'BCRYPT_ROUNDS' => '4',
    // Nothing in the suite may reach Telegram or an AI provider, and no real secret is ever read.
    'TELEGRAM_CLIENT' => 'fake',
    'AI_PROVIDER' => 'fake',
    'TELEGRAM_BOT_TOKEN' => '',
    'DEEPSEEK_API_KEY' => '',
    'TRUSTED_PROXIES' => '',
    // Test-only values (not credentials): webhook path >= 32 chars and its secret header.
    'TELEGRAM_WEBHOOK_PATH' => 'test-webhook-path-'.str_repeat('x7', 12),
    'TELEGRAM_BOT_SECRET_TOKEN' => 'test-secret-'.str_repeat('k9', 8),
] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv("{$name}={$value}");
}
