<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Enums\ReminderState;
use App\Models\ReminderInstance;
use App\Models\User;
use App\Services\Reminder\ReminderSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The buttons under a daily reminder (PRD §25, §26): add a note, nothing today, remind me in an hour. Only the bubble
 * that carries the current send of a still-open reminder acts; any other press (double tap, an old bubble after a
 * snooze) just gets "already answered". The state change is one atomic update, so two presses cannot both win.
 */
class ReminderHandler
{
    public function __construct(
        private readonly TelegramMessenger $messenger,
        private readonly BotMessages $messages,
    ) {}

    public function register(CallbackRouter $router): void
    {
        $router->onReminder(fn (ReminderCallback $data, TelegramUpdate $update, User $user, Language $language) => $this->handle($data, $update, $user, $language));
    }

    public function handle(ReminderCallback $data, TelegramUpdate $update, User $user, Language $language): void
    {
        $callbackId = (string) $update->callbackId;
        $instance = ReminderInstance::query()->find($data->instanceId);

        if ($instance === null) {
            $this->messenger->tryAnswer($callbackId, $this->messages->get('callback.expired', $language));

            return;
        }

        $now = CarbonImmutable::now('UTC');
        $guard = ReminderInstance::query()->whereKey($instance->id)->where('status', ReminderState::Sent->value)->where('telegram_message_id', $update->messageId);

        $reply = match ($data->action) {
            'add' => $guard->update(['status' => ReminderState::Acknowledged, 'action_taken' => 'add']) === 1 ? 'reminder.add_prompt' : null,
            'later' => $this->snooze($guard, $instance, $user, $now),
            default => $guard->update(['status' => ReminderState::Dismissed, 'action_taken' => $data->action]) === 1 ? 'reminder.none_done' : null,
        };

        if ($reply === null) {
            $this->messenger->tryAnswer($callbackId, $this->messages->get('reminder.answered', $language));

            return;
        }

        $this->messenger->tryAnswer($callbackId);

        try {
            $this->messenger->edit($update->chatId, $update->messageId, $this->messages->get($reply, $language), []);
        } catch (TelegramApiException) {
            // The bubble is gone or already shows this text; the state is saved either way.
        }
    }

    /**
     * @param  Builder<ReminderInstance>  $guard
     * @return string|null the message key to show, or null when this press did nothing
     */
    private function snooze($guard, ReminderInstance $instance, User $user, CarbonImmutable $now): ?string
    {
        if ($instance->snooze_count >= ReminderSchedule::MAX_SNOOZES) {
            return $guard->update(['status' => ReminderState::Dismissed, 'action_taken' => 'snooze_limit']) === 1 ? 'reminder.snooze_limit' : null;
        }

        $until = $now->addMinutes(ReminderSchedule::SNOOZE_MINUTES);

        // A snooze that would land tomorrow is not a snooze: the next workday's reminder covers it.
        if (ReminderSchedule::localDate($user, $until) !== ReminderSchedule::localDate($user, $now)) {
            return $guard->update(['status' => ReminderState::Dismissed, 'action_taken' => 'snooze_past_day']) === 1 ? 'reminder.none_done' : null;
        }

        return $guard->update(['status' => ReminderState::Snoozed, 'action_taken' => 'later', 'snoozed_until' => $until, 'snooze_count' => $instance->snooze_count + 1]) === 1
            ? 'reminder.snoozed'
            : null;
    }
}
