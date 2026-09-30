<?php

use App\Models\User;
use App\Support\UserContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
