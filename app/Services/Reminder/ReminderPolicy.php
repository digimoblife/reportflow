<?php

namespace App\Services\Reminder;

use App\Enums\ReminderState;
use App\Enums\ReminderType;
use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\ReminderInstance;
use App\Models\Report;
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

        if ($rule->type === ReminderType::MonthlyReport) {
            return $this->monthly($instance, $user, $now, $date);
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

        return $this->overLimit($user, $now) ? 'daily_limit' : null;
    }

    /**
     * Month-end report reminder: due `days_before` days before the end of the month, still valid for three days after its date
     * (a snooze of one day moves it on); skipped when nothing happened in the month or every active project already has an
     * approved report for it.
     */
    private function monthly(ReminderInstance $instance, User $user, CarbonImmutable $now, ?string $date): ?string
    {
        if ($date === null) {
            return 'expired';
        }

        $deadline = CarbonImmutable::parse($date, $user->timezone)->addDays(3)->startOfDay()->utc();

        if ($now->greaterThanOrEqualTo($deadline)
            || ($instance->status === ReminderState::Scheduled && $instance->next_run_at !== null
                && $now->greaterThan(CarbonImmutable::instance($instance->next_run_at)->addMinutes(ReminderSchedule::EXPIRY_MINUTES)))) {
            return 'expired';
        }

        // The reminder may fire a few days before the end of the month; the report always covers the whole month.
        [$from, $to] = [CarbonImmutable::parse($date)->startOfMonth()->format('Y-m-d'), CarbonImmutable::parse($date)->endOfMonth()->format('Y-m-d')];
        $projects = Activity::query()->whereBetween('activity_date', [$from, $to])->distinct()->pluck('project_id')->all();

        if ($projects === []) {
            return 'no_activity';
        }

        $approved = Report::query()->whereIn('project_id', $projects)->where('status', ReportStatus::Approved)
            ->where('period_start', $from)->where('period_end', $to)->distinct()->pluck('project_id')->all();

        if (count($approved) === count($projects)) {
            return 'report_exists';
        }

        return $this->overLimit($user, $now) ? 'daily_limit' : null;
    }

    /** Every reminder message of the user's local day counts, a reminder's own earlier sends (before a snooze) included. */
    private function overLimit(User $user, CarbonImmutable $now): bool
    {
        $today = ReminderSchedule::localDate($user, $now);

        return (int) ReminderInstance::query()
            ->where('sent_at', '>=', ReminderSchedule::dayStart($user, $today))
            ->where('sent_at', '<', ReminderSchedule::dayStart($user, $today)->addDay())
            ->sum('send_count') >= ReminderSchedule::DAILY_LIMIT;
    }
}
