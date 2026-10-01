<?php

namespace App\Services\Reminder;

use App\Enums\ReminderState;
use App\Jobs\SendReminder;
use App\Models\ReminderInstance;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The once-a-minute pass (`reminders:dispatch`, PRD §25, §28). For every user with reminders on:
 * 1) make today's daily instance when the schedule time has come on a workday (at most one per rule per local day);
 * 2) look at everything due (scheduled, or snoozed past its time), drop what must not go out (ReminderPolicy) and
 *    queue SendReminder for the rest. Idempotent: running it twice in the same minute changes nothing.
 */
class ReminderDispatcher
{
    public function __construct(
        private readonly UserContext $context,
        private readonly ReminderSettings $settings,
        private readonly ReminderPolicy $policy,
    ) {}

    /**
     * @return int how many reminders were queued for sending
     */
    public function run(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $users = $this->context->runAsSystem(fn () => User::query()->where('reminders_enabled', true)->whereNotNull('telegram_user_id')->orderBy('id')->get());
        $queued = 0;

        foreach ($users as $user) {
            $queued += $this->context->runAs($user->id, fn (): int => $this->forUser($user, $now));
        }

        return $queued;
    }

    private function forUser(User $user, CarbonImmutable $now): int
    {
        $rule = $this->settings->daily();
        $this->createToday($user, $rule->id, $rule->enabled, $this->settings->time(), $now);

        $monthly = $this->settings->monthly();
        $this->createMonthEnd($user, $monthly->id, $monthly->enabled, $this->settings->monthlyTime(), $this->settings->monthlyDaysBefore(), $now);

        $queued = 0;

        $due = ReminderInstance::query()->with('rule')
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('status', ReminderState::Scheduled)->where('next_run_at', '<=', $now))
                ->orWhere(fn ($s) => $s->where('status', ReminderState::Snoozed)->where('snoozed_until', '<=', $now)))
            ->orderBy('id')->get();

        foreach ($due as $instance) {
            $reason = $this->policy->blockReason($instance, $user, $now);

            if ($reason !== null) {
                ReminderInstance::query()->whereKey($instance->id)->whereIn('status', [ReminderState::Scheduled, ReminderState::Snoozed])
                    ->update(['status' => ReminderState::Cancelled, 'action_taken' => $reason]);

                continue;
            }

            SendReminder::dispatch($instance->id, $user->id);
            $queued++;
        }

        return $queued;
    }

    private function createToday(User $user, int $ruleId, bool $enabled, string $time, CarbonImmutable $now): void
    {
        $date = ReminderSchedule::localDate($user, $now);
        $at = ReminderSchedule::scheduledAt($user, $date, $time);

        if (! $enabled || ! ReminderSchedule::isWorkday($user, $now) || $now->lessThan($at)
            || $now->greaterThan($at->addMinutes(ReminderSchedule::EXPIRY_MINUTES))) {
            return;
        }

        // ON CONFLICT DO NOTHING on (rule, date): a second scheduler pass is a normal outcome, not an error.
        ReminderInstance::query()->insertOrIgnore([
            'reminder_rule_id' => $ruleId,
            'reminder_date' => $date,
            'next_run_at' => $at,
            'status' => ReminderState::Scheduled->value,
            'send_count' => 0,
            'snooze_count' => 0,
            'created_at' => Carbon::now('UTC'),
            'updated_at' => Carbon::now('UTC'),
        ]);
    }

    /** `$daysBefore` days before the last day of the user's month, at the monthly time (any weekday: a report does not depend on workdays). */
    private function createMonthEnd(User $user, int $ruleId, bool $enabled, string $time, int $daysBefore, CarbonImmutable $now): void
    {
        $local = ReminderSchedule::localNow($user, $now);
        $date = $local->format('Y-m-d');
        $at = ReminderSchedule::scheduledAt($user, $date, $time);

        if (! $enabled || $date !== $local->endOfMonth()->subDays($daysBefore)->format('Y-m-d') || $now->lessThan($at)
            || $now->greaterThan($at->addMinutes(ReminderSchedule::EXPIRY_MINUTES))) {
            return;
        }

        ReminderInstance::query()->insertOrIgnore([
            'reminder_rule_id' => $ruleId,
            'reminder_date' => $date,
            'next_run_at' => $at,
            'status' => ReminderState::Scheduled->value,
            'send_count' => 0,
            'snooze_count' => 0,
            'created_at' => Carbon::now('UTC'),
            'updated_at' => Carbon::now('UTC'),
        ]);
    }
}
