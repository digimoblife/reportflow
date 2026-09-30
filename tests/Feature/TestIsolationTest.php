<?php

use Illuminate\Support\Facades\Redis;
use Predis\Client;

// The suite must never read or write the developer's services (tests/bootstrap.php pins these).

it('points every shared or external service at a test double or a dead host', function () {
    expect(config('database.connections.pgsql.database'))->toBe('reportflow_test')
        ->and(config('database.connections.pgsql.url'))->toBeIn([null, ''])
        ->and(config('cache.default'))->toBe('array')
        ->and(config('session.driver'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync')
        ->and(config('mail.default'))->toBe('array')
        ->and(config('broadcasting.default'))->toBe('log')
        ->and(config('logging.default'))->toBe('sink')
        ->and(config('filesystems.default'))->toBe('local')
        ->and(config('database.redis.default.host'))->toEndWith('.invalid')
        ->and(config('database.redis.default.password'))->toBeIn([null, ''])
        ->and(env('GOTENBERG_URL'))->toEndWith('.invalid:3000')
        ->and(config('telegram.client'))->toBe('fake')
        ->and(config('telegram.token'))->toBeIn([null, ''])
        ->and(config('ai.provider'))->toBe('fake')
        ->and(config('ai.deepseek.api_key'))->toBeIn([null, ''])
        ->and(config('ai.deepseek.base_url'))->toEndWith('.invalid')
        ->and(env('DEEPSEEK_API_KEY'))->toBeIn([null, ''])
        ->and(env('DEEPSEEK_BASE_URL'))->toEndWith('.invalid')
        ->and(config('services.ses.key'))->toBeIn([null, ''])
        ->and(config('services.postmark.key'))->toBeIn([null, ''])
        ->and(config('services.resend.key'))->toBeIn([null, ''])
        ->and(config('services.slack.notifications.bot_user_oauth_token'))->toBeIn([null, '']);
});

it('cannot reach the dev Redis even by accident', function () {
    $failed = false;

    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $failed = true;
    }

    expect($failed)->toBeTrue();
})->skip(! extension_loaded('redis') && ! class_exists(Client::class), 'no Redis client installed');

it('uses the test database for the database queue', function () {
    config(['queue.default' => 'database']);

    expect(config('queue.connections.database.connection'))->toBeIn([null, 'pgsql'])
        ->and(config('database.connections.pgsql.database'))->toBe('reportflow_test');
});
