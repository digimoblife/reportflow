<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One-time dashboard login links for development and emergencies (M5): the Telegram Login Widget needs a public HTTPS
 * domain registered with BotFather, a link does not. Only a hash of the token is stored; a link works once, for
 * five minutes, for the user it was issued to.
 */
class LoginLinkService
{
    public const TTL_SECONDS = 300;

    public function issue(int $userId): string
    {
        $token = Str::random(40);

        Cache::put($this->key($token), $userId, self::TTL_SECONDS);

        return $token;
    }

    /**
     * @return int|null the user id the link was issued to; null if unknown, expired or already used
     */
    public function consume(string $token): ?int
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }

        // add() is atomic: of two simultaneous requests only one gets the marker.
        if (! Cache::add($this->key($token).':used', true, self::TTL_SECONDS)) {
            return null;
        }

        $userId = Cache::pull($this->key($token));

        return is_int($userId) ? $userId : null;
    }

    private function key(string $token): string
    {
        return 'auth:login-link:'.hash('sha256', $token);
    }
}
