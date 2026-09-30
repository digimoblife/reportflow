<?php

use App\Models\InboundMessage;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\Fakes\FakeAiProvider;
use App\Services\Telegram\BotMessages;
use App\Services\Telegram\Fakes\FakeTelegramClient;
use App\Services\Telegram\TelegramClient;
use App\Support\UserContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Database guard is enforced in Tests\TestCase::beforeRefreshingDatabase()
| and Tests\TestCase::setUp() prior to any migration or database operation.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Act as the given user for both auth and the UserContext used by user-scoped models.
 * There is deliberately no default context: tests that touch scoped models must call this.
 */
function actingAsUser(?User $user = null): User
{
    $user ??= User::factory()->create();

    test()->actingAs($user);
    app(UserContext::class)->set($user->id);

    return $user;
}

/**
 * Run a statement that is expected to fail inside a savepoint, so PostgreSQL does not abort
 * the surrounding test transaction and the test can keep querying afterwards.
 *
 * @return Closure(): mixed
 */
function inSavepoint(Closure $callback): Closure
{
    return fn () => DB::transaction($callback);
}

/**
 * Run a callback with a different APP_ENV and/or environment variables, on a freshly booted
 * application, then restore the previous values and boot the application again.
 *
 * Use it for behaviour decided at boot time (route/panel registration). The database guard is
 * re-checked after every boot. A refreshed application gets a new database connection, so do not
 * rely on the surrounding RefreshDatabase transaction inside the callback.
 *
 * @param  array<string, string>  $variables
 */
function withAppEnvironment(string $environment, array $variables, Closure $callback): mixed
{
    $variables = ['APP_ENV' => $environment] + $variables;

    $previous = [];
    foreach (array_keys($variables) as $name) {
        $previous[$name] = [
            'env' => array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
            'server' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
            'process' => getenv($name),
        ];
    }

    $apply = function (string $name, ?string $env, ?string $server, string|false|null $process): void {
        $env === null ? removeFromArray($_ENV, $name) : $_ENV[$name] = $env;
        $server === null ? removeFromArray($_SERVER, $name) : $_SERVER[$name] = $server;
        putenv($process === false || $process === null ? $name : "{$name}={$process}");
    };

    $test = test();

    try {
        foreach ($variables as $name => $value) {
            $apply($name, $value, $value, $value);
        }
        $test->refreshApplication();
        $test->ensureRunningOnTestDatabase();

        return $callback();
    } finally {
        foreach ($previous as $name => $old) {
            $apply($name, $old['env'], $old['server'], $old['process']);
        }
        $test->refreshApplication();
    }
}

/**
 * @param  array<string, mixed>  $array
 */
function removeFromArray(array &$array, string $key): void
{
    unset($array[$key]);
}

/*
|--------------------------------------------------------------------------
| Telegram helpers
|--------------------------------------------------------------------------
*/

function fakeTelegram(): FakeTelegramClient
{
    $client = app(TelegramClient::class);
    assert($client instanceof FakeTelegramClient);

    return $client;
}

function fakeAi(): FakeAiProvider
{
    $ai = app(AiProvider::class);
    assert($ai instanceof FakeAiProvider);

    return $ai;
}

/** A registered bot user (whitelisted by telegram_user_id). */
function registerTelegramUser(int $telegramId = 555001, array $attributes = []): User
{
    return User::factory()->create(['telegram_user_id' => $telegramId] + $attributes);
}

/**
 * POST an update to the webhook with the correct secret unless overridden.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, string>  $headers
 */
function postTelegram(array $payload, ?array $headers = null): TestResponse
{
    $headers ??= ['X-Telegram-Bot-Api-Secret-Token' => (string) config('telegram.secret_token')];

    return test()->postJson('/'.config('telegram.webhook_path'), $payload, $headers);
}

/**
 * Run a callback able to see every user's rows (assertions across users).
 */
function asSystem(Closure $callback): mixed
{
    return app(UserContext::class)->runAsSystem($callback);
}

/**
 * @return Collection<int, InboundMessage>
 */
function storedMessages(): Collection
{
    return asSystem(fn () => InboundMessage::query()->orderBy('id')->get());
}

/**
 * Attach an in-memory handler to the default log channel and return it.
 * Records reach it AFTER the channel's processors, i.e. exactly what a log file would receive.
 */
function captureLogs(): TestHandler
{
    $handler = new TestHandler;
    Log::driver()->getLogger()->pushHandler($handler);

    return $handler;
}

/**
 * Everything the handler saw, as one string (message, context, extra) for "does not contain" checks.
 */
function loggedText(TestHandler $handler): string
{
    return implode("\n", array_map(
        fn (LogRecord $r) => $r->message.' '.json_encode($r->context).' '.json_encode($r->extra),
        $handler->getRecords(),
    ));
}

/** Every variant of a bot message key, in a language. */
function variants(string $key, string $language = 'id'): array
{
    return app(BotMessages::class)->variants($key, $language);
}

/** Whether $text is one of the rendered variants of $key. */
function isVariantOf(string $text, string $key, string $language = 'id', array $replace = []): bool
{
    foreach (variants($key, $language) as $template) {
        foreach ($replace as $name => $value) {
            $template = str_replace(':'.$name, (string) $value, $template);
        }

        if ($template === $text) {
            return true;
        }
    }

    return false;
}

function asUser(int $userId, Closure $callback): mixed
{
    return app(UserContext::class)->runAs($userId, $callback);
}
