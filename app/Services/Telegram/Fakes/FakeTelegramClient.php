<?php

namespace App\Services\Telegram\Fakes;

use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;

/**
 * In-memory Telegram for tests. Records everything; can be told to fail the next calls.
 * Enforces the 4096 character limit like the real API so missing truncation shows up in tests.
 */
final class FakeTelegramClient implements TelegramClient
{
    /** @var list<array{chat_id: int, message_id: int, text: string, reply_to: int|null}> */
    public array $sent = [];

    /** @var list<array{chat_id: int, message_id: int, text: string}> */
    public array $edits = [];

    /** @var list<array{url: string, secret_token: string, allowed_updates: list<string>}> */
    public array $webhooks = [];

    /** @var list<array{commands: list<array{command: string, description: string}>, language_code: string|null}> */
    public array $commandSets = [];

    private int $nextMessageId = 1000;

    /** @var list<TelegramApiException> */
    private array $sendFailures = [];

    /** @var list<TelegramApiException> */
    private array $editFailures = [];

    public function failNextSend(TelegramApiException $e): self
    {
        $this->sendFailures[] = $e;

        return $this;
    }

    public function failNextEdit(TelegramApiException $e): self
    {
        $this->editFailures[] = $e;

        return $this;
    }

    public function sendMessage(int $chatId, string $text, ?int $replyToMessageId = null): int
    {
        if ($this->sendFailures !== []) {
            throw array_shift($this->sendFailures);
        }

        $this->assertLength('sendMessage', $text);
        $id = $this->nextMessageId++;
        $this->sent[] = ['chat_id' => $chatId, 'message_id' => $id, 'text' => $text, 'reply_to' => $replyToMessageId];

        return $id;
    }

    public function editMessageText(int $chatId, int $messageId, string $text): void
    {
        if ($this->editFailures !== []) {
            throw array_shift($this->editFailures);
        }

        $this->assertLength('editMessageText', $text);
        $this->edits[] = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
    }

    public function setWebhook(string $url, #[\SensitiveParameter] string $secretToken, array $allowedUpdates): void
    {
        $this->webhooks[] = ['url' => $url, 'secret_token' => $secretToken, 'allowed_updates' => $allowedUpdates];
    }

    public function setMyCommands(array $commands, ?string $languageCode = null): void
    {
        $this->commandSets[] = ['commands' => $commands, 'language_code' => $languageCode];
    }

    /**
     * Every text this fake was asked to deliver (sends and edits), in order.
     *
     * @return list<string>
     */
    public function allTexts(): array
    {
        return [...array_column($this->sent, 'text'), ...array_column($this->edits, 'text')];
    }

    private function assertLength(string $method, string $text): void
    {
        if (mb_strlen($text) > 4096) {
            throw TelegramApiException::fromResponse($method, 400, 'Bad Request: message is too long');
        }

        if (trim($text) === '') {
            throw TelegramApiException::fromResponse($method, 400, 'Bad Request: message text is empty');
        }
    }
}
