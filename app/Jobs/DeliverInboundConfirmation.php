<?php

namespace App\Jobs;

use App\Enums\InboundMessageStatus;
use App\Enums\MessageSource;
use App\Jobs\Middleware\WithUserContext;
use App\Models\InboundMessage;
use App\Models\User;
use App\Services\Telegram\BotMessages;
use App\Services\Telegram\LanguageDetector;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramMessenger;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns the "⏳" acknowledgement into the confirmation (or error) message (PRD §7).
 *
 * Kept apart from processing: whatever happens here never changes the message status.
 * Error handling follows the Bot API: 400/403 are not retried (fall back to a new message once,
 * then give up and log without content), 429 waits `retry_after`, 5xx and timeouts retry.
 * A cache marker written after success prevents a second confirmation on retry.
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

    public function handle(TelegramMessenger $messenger, BotMessages $messages, LanguageDetector $languages): void
    {
        $deliveredKey = "inbound:{$this->inboundMessageId}:delivered:{$this->kind}";

        $message = InboundMessage::query()->find($this->inboundMessageId);

        if ($message === null
            || $message->source !== MessageSource::Telegram
            || Cache::has($deliveredKey)
            || $message->status !== ($this->kind === self::PROCESSED ? InboundMessageStatus::Processed : InboundMessageStatus::Failed)) {
            return;
        }

        $user = User::query()->findOrFail($this->userId);
        $language = $languages->detect($message->text, $user->default_language);
        $text = $messages->get($this->kind === self::PROCESSED ? 'worklog.recorded_dummy' : 'worklog.failed', $language);
        $chatId = (int) $message->telegram_chat_id;

        try {
            $this->deliver($messenger, $message, $chatId, $text);
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

        Cache::put($deliveredKey, true, self::DELIVERED_TTL_SECONDS);
    }

    /**
     * @throws TelegramApiException
     */
    private function deliver(TelegramMessenger $messenger, InboundMessage $message, int $chatId, string $text): void
    {
        if ($message->reply_message_id !== null) {
            try {
                $messenger->edit($chatId, $message->reply_message_id, $text);

                return;
            } catch (TelegramApiException $e) {
                if ($e->messageNotModified()) {
                    return;
                }

                if ($e->isRetryable()) {
                    throw $e;
                }
                // Permanent (message gone or not editable): fall through and send a fresh message.
            }
        }

        $newId = $messenger->send($chatId, $text);

        InboundMessage::query()->whereKey($message->id)->update(['reply_message_id' => $newId]);
    }
}
