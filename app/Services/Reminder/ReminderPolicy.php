<?php

namespace App\Services\Reminder;

use App\Enums\ReminderState;
use App\Models\Activity;
use App\Models\ReminderInstance;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Whether a reminder may still go out (PRD §25 delivery rules, §28 limits). Pure decision, no writes: the dispatcher
 * and the sending job both ask, because time passes between "due" and "sent". Returns the reason a reminder must NOT
 * be sent (stored in `action_taken` when cancelled), or null when it may go.
 */
class ReminderPolicy
{
    public function blockReason(ReminderInstance $instance, User $user, CarbonImmutable $now): ?string
    {
        $rule = $instance->rule;
        $date = $instance->reminder_date?->format('Y-m-d');

        if (! $user->reminders_enabled || ! $rule->enabled) {
            return 'disabled';
        }

        if ($date === null || ReminderSchedule::localDate($user, $now) !== $date) {
            return 'expired';   // a snooze that ran past midnight
        }

        if ($instance->status === ReminderState::Scheduled && $instance->next_run_at !== null
            && $now->greaterThan(CarbonImmutable::instance($instance->next_run_at)->addMinutes(ReminderSchedule::EXPIRY_MINUTES))) {
            return 'expired';
        }

        if (Activity::query()->whereDate('activity_date', $date)->exists()) {
            return 'already_logged';
        }

        $sentToday = (int) ReminderInstance::query()
            ->where('sent_at', '>=', ReminderSchedule::dayStart($user, $date))
            ->where('sent_at', '<', ReminderSchedule::dayStart($user, $date)->addDay())
            ->where('id', '!=', $instance->id)
            ->sum('send_count');

        return $sentToday >= ReminderSchedule::DAILY_LIMIT ? 'daily_limit' : null;
    }
}
