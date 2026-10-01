<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;

/**
 * Verifies the data the Telegram Login Widget sends back (PRD §56): HMAC-SHA256 of the sorted `key=value` lines
 * with SHA256(bot token) as key, compared in constant time, fresh `auth_date`, and single use inside the validity
 * window (replay protection). The bot token is read from config only and never logged.
 *
 * @see https://core.telegram.org/widgets/login#checking-authorization
 */
class TelegramLoginVerifier
{
    public const MAX_AGE_SECONDS = 300;

    private const MAX_FIELDS = 12;

    /**
     * @param  array<array-key, mixed>  $data  the query string of the callback
     * @return int|null the Telegram user id, or null when the data cannot be trusted
     */
    public function verify(array $data): ?int
    {
        $token = config('telegram.token');

        if (! is_string($token) || $token === '' || count($data) > self::MAX_FIELDS) {
            return null;
        }

        $hash = $data['hash'] ?? null;
        unset($data['hash']);

        if (! is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            return null;
        }

        $lines = [];

        foreach ($data as $key => $value) {
            if (! is_string($key) || ! is_string($value) || mb_strlen($value) > 512) {
                return null;
            }

            $lines[$key] = $key.'='.$value;
        }

        ksort($lines);

        $expected = hash_hmac('sha256', implode("\n", $lines), hash('sha256', $token, true));

        if (! hash_equals($expected, $hash)) {
            return null;
        }

        $id = $data['id'] ?? null;
        $authDate = $data['auth_date'] ?? null;

        if (! is_string($id) || preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1
            || ! is_string($authDate) || preg_match('/^[0-9]{1,12}$/', $authDate) !== 1) {
            return null;
        }

        $age = time() - (int) $authDate;

        if ($age > self::MAX_AGE_SECONDS || $age < -60) {
            return null;
        }

        // Each signed payload works once, so a leaked callback URL cannot be replayed within the window.
        if (! Cache::add('auth:telegram:used:'.$hash, true, self::MAX_AGE_SECONDS + 60)) {
            return null;
        }

        return (int) $id;
    }
}
