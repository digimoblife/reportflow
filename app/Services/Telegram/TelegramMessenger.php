<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Log;

/**
 * Sends bot messages through the TelegramClient with the 4096 limit applied.
 * `trySend` is for best-effort notices (failure is logged without any message content).
 */
class TelegramMessenger
{
    public function __construct(private readonly TelegramClient $client) {}

    /**
     * @throws TelegramApiException
     */
    public function send(int $chatId, string $text, ?int $replyTo = null): int
    {
        return $this->client->sendMessage($chatId, TelegramText::fit($text), $replyTo);
    }

    /**
     * @throws TelegramApiException
     */
    public function edit(int $chatId, int $messageId, string $text): void
    {
        $this->client->editMessageText($chatId, $messageId, TelegramText::fit($text));
    }

    /**
     * Best effort: returns the new message id, or null when Telegram refused or was unreachable.
     */
    public function trySend(int $chatId, string $text, ?int $replyTo = null): ?int
    {
        try {
            return $this->send($chatId, $text, $replyTo);
        } catch (TelegramApiException $e) {
            Log::warning('telegram.send_failed', ['method' => $e->apiMethod, 'status' => $e->httpStatus, 'description' => $e->description]);

            return null;
        }
    }
}
