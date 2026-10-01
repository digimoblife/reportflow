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
    'BROADCAST_CONNECTION' => 'log', // not "null": env() turns that string into PHP null
    'BCRYPT_ROUNDS' => '4',
    // Nothing in the suite may reach Telegram or an AI provider, and no real secret is ever read.
    'TELEGRAM_CLIENT' => 'fake',
    'AI_PROVIDER' => 'fake',
    'TELEGRAM_BOT_TOKEN' => '',
    'TELEGRAM_BOT_USERNAME' => '',
    'DEEPSEEK_API_KEY' => '',
    'TRUSTED_PROXIES' => '',
    // Every other setting that points at a shared or external service. Hosts under .invalid never resolve,
    // so a test that reaches for Redis, Gotenberg, DeepSeek, mail or AWS fails loudly instead of hitting dev.
    'REDIS_HOST' => 'redis.invalid',
    'REDIS_PORT' => '1',
    'REDIS_PASSWORD' => '',
    'REDIS_URL' => '',
    'REDIS_CLIENT' => 'phpredis',
    'GOTENBERG_URL' => 'http://gotenberg.invalid:3000',
    'DEEPSEEK_BASE_URL' => 'http://deepseek.invalid',
    'DB_URL' => '',
    'LOG_CHANNEL' => 'sink',
    'LOG_STACK' => 'sink',
    'LOG_SLACK_WEBHOOK_URL' => '',
    'FILESYSTEM_DISK' => 'local',
    'AWS_ACCESS_KEY_ID' => '',
    'AWS_SECRET_ACCESS_KEY' => '',
    'POSTMARK_API_KEY' => '',
    'RESEND_API_KEY' => '',
    'SLACK_BOT_USER_OAUTH_TOKEN' => '',
    'APP_KEY' => 'base64:'.'dGVzdHRlc3R0ZXN0dGVzdHRlc3R0ZXN0dGVzdHRlc3Q=',
    // Test-only values (not credentials): webhook path >= 32 chars and its secret header.
    'TELEGRAM_WEBHOOK_PATH' => 'test-webhook-path-'.str_repeat('x7', 12),
    'TELEGRAM_BOT_SECRET_TOKEN' => 'test-secret-'.str_repeat('k9', 8),
] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv("{$name}={$value}");
}
