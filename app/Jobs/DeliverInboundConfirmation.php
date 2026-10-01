<?php

namespace App\Jobs;

use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\MessageSource;
use App\Enums\OutcomeState;
use App\Jobs\Middleware\WithUserContext;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\User;
use App\Services\Telegram\BotMessages;
use App\Services\Telegram\ConfirmationComposer;
use App\Services\Telegram\LanguageDetector;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramMessenger;
use App\Services\Worklog\Outcome;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns the "⏳" acknowledgement into the confirmation (or error) message and sends the clarification questions (PRD §7, §13, §21).
 *
 * Kept apart from processing: whatever happens here never changes the message status.
 * Error handling follows the Bot API: 400/403 are not retried (fall back to a new message once,
 * then give up and log without content), 429 waits `retry_after`, 5xx and timeouts retry.
 * A cache marker written after success prevents a second confirmation on retry; each question message id is
 * stored in the outcome before the job finishes, so a retry only sends the questions still missing.
 */
class DeliverInboundConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PROCESSED = 'processed';

    public const FAILED = 'failed';

    private const DELIVERED_TTL_SECONDS = 604_800;

    public function __construct(
        public readonly int $inboundMessageId,
        public readonly int $userId,
        public readonly string $kind,
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

    public function handle(TelegramMessenger $messenger, BotMessages $messages, LanguageDetector $languages, ConfirmationComposer $composer): void
    {
        $message = InboundMessage::query()->find($this->inboundMessageId);
        $deliveredKey = "inbound:{$this->inboundMessageId}:{$message?->reprocess_count}:delivered:{$this->kind}";

        if ($message === null
            || $message->source !== MessageSource::Telegram
            || Cache::has($deliveredKey)
            || ! in_array($message->status, $this->kind === self::PROCESSED ? [InboundMessageStatus::Processed, InboundMessageStatus::NeedsClarification] : [InboundMessageStatus::Failed], true)) {
            return;
        }

        $user = User::query()->findOrFail($this->userId);
        $language = $languages->detect($message->text, $user->default_language);
        $chatId = (int) $message->telegram_chat_id;
        $outcome = $this->kind === self::PROCESSED ? (Outcome::fromArray($message->outcome) ?? new Outcome([])) : null;

        if ($outcome === null) {
            ['text' => $text, 'keyboard' => $keyboard] = ['text' => $messages->get('worklog.failed', $language), 'keyboard' => []];
        } else {
            ['text' => $text, 'keyboard' => $keyboard] = $composer->confirmation($message, $outcome, $language, $user->timezone);
        }

        try {
            $confirmationId = $this->deliver($messenger, $message, $chatId, $text, $keyboard);

            if ($outcome !== null) {
                $outcome = $this->sendQuestions($messenger, $composer, $message, $outcome->withConfirmationMessage($confirmationId), $language, $chatId);
            }
        } catch (TelegramApiException $e) {
            if ($e->isRetryable()) {
                $this->release(max($e->retryAfter ?? 0, 10));

                return;
            }

            // 400/403 and friends: nothing to gain from retrying.
            Log::warning('inbound.confirmation_undeliverable', [
                'inbound_message_id' => $message->id,
                'method' => $e->apiMethod,
                'status' => $e->httpStatus,
            ]);

            return;
        }

        $this->refreshCorrectedBubble($messenger, $composer, $message, $language, $user->timezone, $chatId);

        Cache::put($deliveredKey, true, self::DELIVERED_TTL_SECONDS);
    }

    /**
     * After a reply correction replaced the old result, the old confirmation shows it as cancelled (no buttons).
     */
    private function refreshCorrectedBubble(TelegramMessenger $messenger, ConfirmationComposer $composer, InboundMessage $message, Language $language, string $timezone, int $chatId): void
    {
        $original = $message->correction_of_id === null ? null : InboundMessage::query()->find($message->correction_of_id);
        $outcome = $original === null ? null : Outcome::fromArray($original->outcome);

        if ($original === null || $outcome === null || $original->reply_message_id === null || $outcome->count(OutcomeState::Undone) === 0) {
            return;
        }

        $view = $composer->confirmation($original, $outcome, $language, $timezone);

        try {
            $messenger->edit($chatId, $original->reply_message_id, $view['text'], $view['keyboard'] ?? []);
        } catch (TelegramApiException) {
            // Not modified, gone or not editable: the new confirmation already tells the story.
        }
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>  $keyboard
     * @return int the id of the message that now shows the confirmation
     *
     * @throws TelegramApiException
     */
    private function deliver(TelegramMessenger $messenger, InboundMessage $message, int $chatId, string $text, array $keyboard): int
    {
        if ($message->reply_message_id !== null) {
            try {
                $messenger->edit($chatId, $message->reply_message_id, $text, $keyboard);

                return $message->reply_message_id;
            } catch (TelegramApiException $e) {
                if ($e->messageNotModified()) {
                    return $message->reply_message_id;
                }

                if ($e->isRetryable()) {
                    throw $e;
                }
                // Permanent (message gone or not editable): fall through and send a fresh message.
            }
        }

        $newId = $messenger->send($chatId, $text, null, $keyboard === [] ? null : $keyboard);

        InboundMessage::query()->whereKey($message->id)->update(['reply_message_id' => $newId]);

        return $newId;
    }

    /**
     * One question message per pending item that has none yet; the id is saved immediately.
     *
     * @throws TelegramApiException
     */
    private function sendQuestions(TelegramMessenger $messenger, ConfirmationComposer $composer, InboundMessage $message, Outcome $outcome, Language $language, int $chatId): Outcome
    {
        $projects = array_values(Project::query()->where('status', 'active')->orderBy('id')->get()->all());

        foreach ($outcome->items as $item) {
            if ($item->state !== OutcomeState::Pending || $item->questionMessageId !== null) {
                continue;
            }

            ['text' => $text, 'keyboard' => $keyboard] = $composer->question($message, $item, $language, $projects);
            $id = $messenger->send($chatId, $text, null, $keyboard);
            $outcome = $outcome->replaceItem($item->with(['question_message_id' => $id]));
            InboundMessage::query()->whereKey($message->id)->update(['outcome' => $outcome->toArray()]);
        }

        InboundMessage::query()->whereKey($message->id)->update(['outcome' => $outcome->toArray()]);

        return $outcome;
    }
}
