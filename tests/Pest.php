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
