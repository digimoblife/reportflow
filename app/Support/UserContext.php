<?php

namespace App\Support;

use App\Exceptions\MissingUserContextException;

/**
 * The user on whose behalf the current request, job or command runs (CLAUDE.md rule 11).
 *
 * There is no global default: queries on user-scoped models fail closed when no user is set.
 * Code that must see every user's data (scheduler, seeders, maintenance commands) opts in
 * explicitly with runAsSystem(). Registered as a scoped binding, so it is also flushed
 * between queued jobs.
 */
final class UserContext
{
    private ?int $userId = null;

    private bool $system = false;

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * @throws MissingUserContextException
     */
    public function requireUserId(): int
    {
        return $this->userId ?? throw new MissingUserContextException;
    }

    public function set(int $userId): void
    {
        $this->userId = $userId;
        $this->system = false;
    }

    public function clear(): void
    {
        $this->userId = null;
        $this->system = false;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(int $userId, callable $callback): mixed
    {
        return $this->runWith($userId, false, $callback);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAsSystem(callable $callback): mixed
    {
        return $this->runWith(null, true, $callback);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function runWith(?int $userId, bool $system, callable $callback): mixed
    {
        [$previousUserId, $previousSystem] = [$this->userId, $this->system];
        [$this->userId, $this->system] = [$userId, $system];

        try {
            return $callback();
        } finally {
            [$this->userId, $this->system] = [$previousUserId, $previousSystem];
        }
    }
}
