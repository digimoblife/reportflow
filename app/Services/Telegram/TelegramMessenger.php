<?php

namespace App\Services\Telegram;

use App\Services\Ops\OpsEvents;
use Illuminate\Support\Facades\Log;

/**
 * Sends bot messages through the TelegramClient with the 4096 limit applied.
 * `trySend` is for best-effort notices (failure is logged without any message content).
 */
class TelegramMessenger
{
    public function __construct(private readonly TelegramClient $client) {}

    /**
     * @param  list<list<array<string, string>>>|null  $keyboard
     *
     * @throws TelegramApiException
     */
    public function send(int $chatId, string $text, ?int $replyTo = null, ?array $keyboard = null): int
    {
        return $this->client->sendMessage($chatId, TelegramText::fit($text), $replyTo, $keyboard);
    }

    /**
     * @param  list<list<array<string, string>>>|null  $keyboard  null = keep the buttons, [] = remove them
     *
     * @throws TelegramApiException
     */
    public function edit(int $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        $this->client->editMessageText($chatId, $messageId, TelegramText::fit($text), $keyboard);
    }

    /**
     * @param  list<list<array<string, string>>>|null  $keyboard
     *
     * @throws TelegramApiException
     */
    public function sendDocument(int $chatId, string $filename, #[\SensitiveParameter] string $contents, ?string $caption = null, ?array $keyboard = null): int
    {
        return $this->client->sendDocument($chatId, $filename, $contents, $caption === null ? null : TelegramText::fit($caption, 1024), $keyboard);
    }

    /**
     * Best effort: a failed toast must never fail the button press itself.
     */
    public function tryAnswer(string $callbackQueryId, ?string $text = null): void
    {
        try {
            $this->client->answerCallbackQuery($callbackQueryId, $text);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.answer_failed', ['status' => $e->httpStatus, 'description' => $e->description]);
        }
    }

    /**
     * Best effort: returns the new message id, or null when Telegram refused or was unreachable.
     *
     * @param  list<list<array<string, string>>>|null  $keyboard
     */
    public function trySend(int $chatId, string $text, ?int $replyTo = null, ?array $keyboard = null): ?int
    {
        try {
            return $this->send($chatId, $text, $replyTo, $keyboard);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.send_failed', ['method' => $e->apiMethod, 'status' => $e->httpStatus, 'description' => $e->description]);
            OpsEvents::record(OpsEvents::TELEGRAM_FAILED, ['method' => $e->apiMethod, 'status' => $e->httpStatus]);

            return null;
        }
    }
}
