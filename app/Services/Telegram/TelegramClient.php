<?php

namespace App\Services\Telegram;

/**
 * The only door to the Telegram Bot API. Real implementation: HttpTelegramClient.
 * Tests bind FakeTelegramClient; nothing in the test suite may reach the network.
 */
interface TelegramClient
{
    /**
     * @param  list<list<array{text: string, callback_data: string}>>|null  $inlineKeyboard
     * @return int the message_id of the sent message
     *
     * @throws TelegramApiException
     */
    public function sendMessage(int $chatId, string $text, ?int $replyToMessageId = null, ?array $inlineKeyboard = null): int;

    /**
     * @param  list<list<array{text: string, callback_data: string}>>|null  $inlineKeyboard  null = leave the buttons as they are, [] = remove them
     *
     * @throws TelegramApiException
     */
    public function editMessageText(int $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): void;

    /**
     * Acknowledge a button press (stops the client's loading spinner); optional short toast text.
     *
     * @throws TelegramApiException
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void;

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
