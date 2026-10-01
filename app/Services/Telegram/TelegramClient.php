<?php

namespace App\Services\Telegram;

/**
 * The only door to the Telegram Bot API. Real implementation: HttpTelegramClient.
 * Tests bind FakeTelegramClient; nothing in the test suite may reach the network.
 */
interface TelegramClient
{
    /**
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard
     * @return int the message_id of the sent message
     *
     * @throws TelegramApiException
     */
    public function sendMessage(int $chatId, string $text, ?int $replyToMessageId = null, ?array $inlineKeyboard = null): int;

    /**
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard  null = leave the buttons as they are, [] = remove them
     *
     * @throws TelegramApiException
     */
    public function editMessageText(int $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): void;

    /**
     * Sends a file (a report PDF or Markdown) to a chat. `$contents` stays in memory; nothing about it is logged.
     *
     * @param  list<list<array<string, string>>>|null  $inlineKeyboard
     * @return int the message_id of the sent message
     *
     * @throws TelegramApiException
     */
    public function sendDocument(int $chatId, string $filename, #[\SensitiveParameter] string $contents, ?string $caption = null, ?array $inlineKeyboard = null): int;

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
