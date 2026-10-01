<?php

namespace App\Jobs;

use App\Enums\Language;
use App\Enums\MessageSource;
use App\Enums\OutcomeState;
use App\Jobs\Middleware\WithUserContext;
use App\Models\InboundMessage;
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

/**
 * Keeps the Telegram bubbles of a message in step after the dashboard changed it (PRD §23 two-way sync).
 * Idempotent: every action is an edit to a text derived from the stored outcome, so running twice (or after a
 * Telegram-side change) is harmless; "message is not modified" is not an error. Carries ids only.
 *
 * - ANSWERED: the question bubble of one item shows it was answered (with the result), then the confirmation is refreshed.
 * - REFRESH: the confirmation bubble is redrawn from the outcome (after an undo or a correction).
 * - REPROCESSED: old question bubbles are closed and the confirmation bubble goes back to "⏳".
 */
class SyncTelegramBubbles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ANSWERED = 'answered';

    public const REFRESH = 'refresh';

    public const REPROCESSED = 'reprocessed';

    /**
     * @param  list<int>  $questionMessageIds  bubbles of the previous run (REPROCESSED only)
     */
    public function __construct(
        public readonly int $inboundMessageId,
        public readonly int $userId,
        public readonly string $kind,
        public readonly ?int $item = null,
        public readonly array $questionMessageIds = [],
    ) {
        $this->onQueue('default');
    }

    /**
     * Queues the sync only for messages that have a Telegram chat.
     *
     * @param  list<int>  $questionMessageIds
     */
    public static function dispatchFor(InboundMessage $message, string $kind, ?int $item = null, array $questionMessageIds = []): void
    {
        if ($message->source === MessageSource::Telegram && $message->telegram_chat_id !== null) {
            self::dispatch($message->id, $message->user_id, $kind, $item, $questionMessageIds);
        }
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

        if ($message === null || $message->source !== MessageSource::Telegram || $message->telegram_chat_id === null) {
            return;
        }

        $user = User::query()->findOrFail($this->userId);
        $language = $languages->detect($message->text, $user->default_language);
        $chatId = $message->telegram_chat_id;
        $outcome = Outcome::fromArray($message->outcome);

        try {
            match ($this->kind) {
                self::ANSWERED => $this->answered($messenger, $messages, $composer, $message, $outcome, $language, $user->timezone, $chatId),
                self::REPROCESSED => $this->reprocessed($messenger, $messages, $message, $language, $chatId),
                default => $this->refreshConfirmation($messenger, $composer, $message, $outcome, $language, $user->timezone, $chatId),
            };
        } catch (TelegramApiException $e) {
            if ($e->isRetryable()) {
                $this->release(max($e->retryAfter ?? 0, 10));
            }
            // 400/403 (message gone, not editable): nothing to gain from retrying; the dashboard is the source of truth.
        }
    }

    private function answered(TelegramMessenger $messenger, BotMessages $messages, ConfirmationComposer $composer, InboundMessage $message, ?Outcome $outcome, Language $language, string $timezone, int $chatId): void
    {
        $item = $outcome?->item($this->item ?? -1);

        if ($outcome === null || $item === null) {
            return;
        }

        if ($item->questionMessageId !== null) {
            $text = $messages->get('sync.answered_via_dashboard', $language);
            $keyboard = [];

            if ($item->state === OutcomeState::Applied) {
                $text .= "\n\n".$composer->block($item, $language, $timezone);
                $keyboard = $composer->correctionKeyboard($message, [$item], $language);
            }

            $this->edit($messenger, $chatId, $item->questionMessageId, $text, $keyboard);
        }

        $this->refreshConfirmation($messenger, $composer, $message, $outcome, $language, $timezone, $chatId);
    }

    private function refreshConfirmation(TelegramMessenger $messenger, ConfirmationComposer $composer, InboundMessage $message, ?Outcome $outcome, Language $language, string $timezone, int $chatId): void
    {
        if ($outcome === null || $message->reply_message_id === null) {
            return;
        }

        $view = $composer->confirmation($message, $outcome, $language, $timezone);
        $this->edit($messenger, $chatId, $message->reply_message_id, $view['text'], $view['keyboard'] ?? []);

        foreach ($outcome->items as $item) {
            if ($item->questionMessageId !== null && $item->state !== OutcomeState::Pending && $item->state !== OutcomeState::Applied) {
                $bubble = $composer->view($message, $item->questionMessageId, $language, $timezone);

                if ($bubble !== null) {
                    $this->edit($messenger, $chatId, $item->questionMessageId, $bubble['text'], $bubble['keyboard']);
                }
            }
        }
    }

    private function reprocessed(TelegramMessenger $messenger, BotMessages $messages, InboundMessage $message, Language $language, int $chatId): void
    {
        foreach ($this->questionMessageIds as $id) {
            $this->edit($messenger, $chatId, $id, $messages->get('reprocess.superseded', $language), []);
        }

        if ($message->reply_message_id !== null) {
            $this->edit($messenger, $chatId, $message->reply_message_id, $messages->get('worklog.ack', $language), []);
        }
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>  $keyboard
     */
    private function edit(TelegramMessenger $messenger, int $chatId, int $messageId, string $text, array $keyboard): void
    {
        try {
            $messenger->edit($chatId, $messageId, $text, $keyboard);
        } catch (TelegramApiException $e) {
            if (! $e->messageNotModified()) {
                throw $e;
            }
        }
    }
}
