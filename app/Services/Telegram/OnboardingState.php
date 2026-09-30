<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

/**
 * "Waiting for the first project name" after /start. Kept in the cache, not in the schema:
 * losing it (cache flush, 24 h expiry) only means the user sends /start again.
 */
class OnboardingState
{
    private const TTL_SECONDS = 86_400;

    public function awaitProjectName(int $userId): void
    {
        Cache::put($this->key($userId), 'project_name', self::TTL_SECONDS);
    }

    public function isAwaitingProjectName(int $userId): bool
    {
        return Cache::get($this->key($userId)) === 'project_name';
    }

    public function clear(int $userId): void
    {
        Cache::forget($this->key($userId));
    }

    private function key(int $userId): string
    {
        return "tg:state:{$userId}";
    }
}
