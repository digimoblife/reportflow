<?php

namespace App\Services\Reminder;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Time arithmetic for reminders. Times are stored as "HH:MM" in the user's timezone; everything this class hands out
 * is UTC (CLAUDE.md), converted at the boundary. A "local day" is the user's calendar day.
 */
final class ReminderSchedule
{
    public const DEFAULT_TIME = '18:00';

    /** A reminder later than this after its schedule is dropped instead of sent (a scheduler outage must not wake the user at 23:00). */
    public const EXPIRY_MINUTES = 120;

    public const DAILY_LIMIT = 3;

    public const MAX_SNOOZES = 3;

    public const SNOOZE_MINUTES = 60;

    public static function validTime(string $time): bool
    {
        return preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time) === 1;
    }

    /**
     * "9", "9:5", "18.30" and "18:30" all mean a time of day; null when it is not one.
     */
    public static function parseTime(string $input): ?string
    {
        if (preg_match('/^\s*([0-9]{1,2})\s*[:.]\s*([0-9]{1,2})\s*$/', $input, $m) !== 1 || strlen($m[2]) !== 2) {
            return null;
        }

        $time = sprintf('%02d:%s', (int) $m[1], $m[2]);

        return self::validTime($time) ? $time : null;
    }

    public static function localNow(User $user, CarbonImmutable $now): CarbonImmutable
    {
        return $now->setTimezone($user->timezone);
    }

    /** The user's calendar date (Y-m-d) at $now. */
    public static function localDate(User $user, CarbonImmutable $now): string
    {
        return self::localNow($user, $now)->format('Y-m-d');
    }

    public static function isWorkday(User $user, CarbonImmutable $now): bool
    {
        $day = strtolower(self::localNow($user, $now)->format('D'));   // mon, tue, ...

        return in_array($day, array_map('strval', (array) $user->workdays), true);
    }

    /** The schedule instant (UTC) on local calendar date $date. */
    public static function scheduledAt(User $user, string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}:00", $user->timezone)->utc();
    }

    /** Start of the user's local day that contains $date (UTC). */
    public static function dayStart(User $user, string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $user->timezone)->startOfDay()->utc();
    }
}
