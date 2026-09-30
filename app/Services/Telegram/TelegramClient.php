<?php

namespace App\Services\Telegram;

/**
 * The only door to the Telegram Bot API. Real implementation: HttpTelegramClient.
 * Tests bind FakeTelegramClient; nothing in the test suite may reach the network.
 */
interface TelegramClient
{
    /**
     * @return int the message_id of the sent message
     *
     * @throws TelegramApiException
     */
    public function sendMessage(int $chatId, string $text, ?int $replyToMessageId = null): int;

    /**
     * @throws TelegramApiException
     */
    public function editMessageText(int $chatId, int $messageId, string $text): void;

    /**
     * @param  list<string>  $allowedUpdates
     *
     * @throws TelegramApiException
     */
    public function setWebhook(string $url, #[\SensitiveParameter] string $secretToken, array $allowedUpdates): void;

    /**
     * @param  list<array{command: string, description: string}>  $commands
     *
     * @throws TelegramApiException
     */
    public function setMyCommands(array $commands, ?string $languageCode = null): void;
}
