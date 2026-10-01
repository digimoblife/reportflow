<?php

namespace App\Jobs;

use App\Enums\ReminderState;
use App\Jobs\Middleware\WithUserContext;
use App\Models\ReminderInstance;
use App\Models\User;
use App\Services\Reminder\ReminderPolicy;
use App\Services\Telegram\BotMessages;
use App\Services\Telegram\ReminderCallback;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramMessenger;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

/**
 * Sends one daily reminder (PRD §25). Idempotent and safe to retry:
 * - the instance is claimed atomically (scheduled|snoozed -> sent, `send_count` + 1), so a duplicate job sends nothing;
 * - a retry after a Telegram hiccup finds the claim without a message id and only sends;
 * - the rules are asked again right before sending (the user may have logged work meanwhile).
 * Carries ids only; nothing user-written is in the payload or the logs.
 */
class SendReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $reminderInstanceId,
        public readonly int $userId,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new WithUserContext($this->userId)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(TelegramMessenger $messenger, BotMessages $messages, ReminderPolicy $policy): void
    {
        $now = CarbonImmutable::now('UTC');
        $instance = ReminderInstance::query()->with('rule')->find($this->reminderInstanceId);
        $user = User::query()->find($this->userId);

        if ($instance === null || $user === null || $user->telegram_user_id === null) {
            return;
        }

        $retryOfClaim = $instance->status === ReminderState::Sent && $instance->telegram_message_id === null
            && $instance->sent_at !== null && $instance->sent_at->greaterThan($now->subMinutes(15));

        if (! $retryOfClaim) {
            if (! in_array($instance->status, [ReminderState::Scheduled, ReminderState::Snoozed], true)) {
                return;
            }

            $reason = $policy->blockReason($instance, $user, $now);

            if ($reason !== null) {
                $this->cancel($instance->id, $reason);

                return;
            }

            $claimed = ReminderInstance::query()->whereKey($instance->id)
                ->whereIn('status', [ReminderState::Scheduled->value, ReminderState::Snoozed->value])
                ->update(['status' => ReminderState::Sent, 'sent_at' => $now, 'snoozed_until' => null, 'telegram_message_id' => null, 'send_count' => $instance->send_count + 1]);

            if ($claimed === 0) {
                return;
            }
        }

        $language = $user->default_language;
        $lang = $language->value;
        $button = fn (string $label, string $action): array => [
            'text' => (string) Lang::get('ui.buttons.'.$label, [], $lang),
            'callback_data' => (new ReminderCallback($instance->id, $action))->encode(),
        ];

        try {
            $id = $messenger->send($user->telegram_user_id, $messages->get('reminder.daily', $language), null, [
                [$button('reminder_add', 'add')],
                [$button('reminder_none', 'none'), $button('reminder_later', 'later')],
            ]);
        } catch (TelegramApiException $e) {
            if ($e->isRetryable()) {
                $this->release(max($e->retryAfter ?? 0, 15));

                return;
            }

            Log::warning('reminder.undeliverable', ['reminder_instance_id' => $instance->id, 'method' => $e->apiMethod, 'status' => $e->httpStatus]);
            $this->cancel($instance->id, 'undeliverable');

            return;
        }

        ReminderInstance::query()->whereKey($instance->id)->update(['telegram_message_id' => $id]);
    }

    private function cancel(int $id, string $reason): void
    {
        ReminderInstance::query()->whereKey($id)
            ->whereIn('status', [ReminderState::Scheduled->value, ReminderState::Snoozed->value, ReminderState::Sent->value])
            ->update(['status' => ReminderState::Cancelled, 'action_taken' => $reason]);
    }
}
