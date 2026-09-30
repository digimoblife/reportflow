<?php

namespace App\Jobs\Middleware;

use App\Support\UserContext;
use Closure;

/**
 * Runs a queued job as the given user. Jobs carry user_id in their payload because there is
 * no authenticated session in a worker; the context is restored afterwards so it never
 * leaks into the next job handled by the same long-lived worker.
 */
class WithUserContext
{
    public function __construct(public readonly int $userId) {}

    public function handle(object $job, Closure $next): mixed
    {
        return app(UserContext::class)->runAs($this->userId, fn () => $next($job));
    }
}
