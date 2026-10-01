<?php

namespace App\Services\Reminder;

use App\Enums\ReminderPriority;
use App\Enums\ReminderState;
use App\Enums\ReminderType;
use App\Models\ReminderInstance;
use App\Models\ReminderRule;
use App\Models\User;

/**
 * The one place that writes reminder preferences (Telegram `/reminder`, dashboard Settings). Needs a UserContext:
 * rules are user-scoped. Preferences are the user's global switch (`users.reminders_enabled`, `workdays`) and the
 * global daily rule (`schedule.time`, `enabled`). Project-specific rules arrive in Phase 3.
 */
class ReminderSettings
{
    public function daily(): ReminderRule
    {
        return ReminderRule::query()->firstOrCreate(
            ['project_id' => null, 'type' => ReminderType::DailyWorklog],
            ['schedule' => ['time' => ReminderSchedule::DEFAULT_TIME], 'config' => [], 'priority' => ReminderPriority::Normal, 'enabled' => true],
        );
    }

    public const DEFAULT_MONTHLY_TIME = '09:00';

    /** The monthly report reminder: last day of the month at `schedule.time` (PRD §25, §32). */
    public function monthly(): ReminderRule
    {
        return ReminderRule::query()->firstOrCreate(
            ['project_id' => null, 'type' => ReminderType::MonthlyReport],
            ['schedule' => ['day' => 'last', 'time' => self::DEFAULT_MONTHLY_TIME], 'config' => [], 'priority' => ReminderPriority::Normal, 'enabled' => true],
        );
    }

    public function monthlyTime(): string
    {
        $time = $this->monthly()->schedule['time'] ?? null;

        return is_string($time) && ReminderSchedule::validTime($time) ? $time : self::DEFAULT_MONTHLY_TIME;
    }

    public function setMonthlyTime(string $time): bool
    {
        $time = ReminderSchedule::parseTime($time);

        if ($time === null) {
            return false;
        }

        $rule = $this->monthly();
        $rule->update(['schedule' => ['time' => $time] + (array) $rule->schedule]);

        return true;
    }

    public function setMonthlyEnabled(bool $enabled): void
    {
        $this->monthly()->update(['enabled' => $enabled]);

        if (! $enabled) {
            ReminderInstance::query()->where('reminder_rule_id', $this->monthly()->id)->whereIn('status', [ReminderState::Scheduled, ReminderState::Snoozed])
                ->update(['status' => ReminderState::Cancelled, 'action_taken' => 'disabled']);
        }
    }

    public function time(): string
    {
        $time = $this->daily()->schedule['time'] ?? null;

        return is_string($time) && ReminderSchedule::validTime($time) ? $time : ReminderSchedule::DEFAULT_TIME;
    }

    /**
     * @return bool false when $time is not a valid HH:MM
     */
    public function setTime(string $time): bool
    {
        $time = ReminderSchedule::parseTime($time);

        if ($time === null) {
            return false;
        }

        $rule = $this->daily();
        $rule->update(['schedule' => ['time' => $time] + (array) $rule->schedule]);

        return true;
    }

    public function setEnabled(User $user, bool $enabled): void
    {
        $user->update(['reminders_enabled' => $enabled]);

        if ($enabled) {
            $this->daily();
            $this->monthly();

            return;
        }

        // Nothing waiting may still fire after the user switched reminders off.
        ReminderInstance::query()
            ->whereIn('status', [ReminderState::Scheduled, ReminderState::Snoozed])
            ->update(['status' => ReminderState::Cancelled, 'action_taken' => 'disabled']);
    }
}
