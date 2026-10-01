<?php

namespace App\Services\Telegram;

use LogicException;
use SensitiveParameter;

/**
 * The parts of a Telegram update the bot understands (message and edited_message, private chats).
 *
 * This object holds the RAW, un-redacted text (CLAUDE.md rule 6). It must never be logged,
 * serialized or queued: the text/caption parameters are #[SensitiveParameter], debug output hides
 * them and serialization is refused. Redact immediately, then work with the redacted string.
 */
final class TelegramUpdate
{
    public const MESSAGE = 'message';

    public const EDITED_MESSAGE = 'edited_message';

    public const CALLBACK_QUERY = 'callback_query';

    /**
     * @param  list<string>  $attachmentTypes  types only (photo, voice, ...), never file ids
     */
    private function __construct(
        public readonly int $updateId,
        public readonly string $kind,
        public readonly int $chatId,
        public readonly string $chatType,
        public readonly int $messageId,
        public readonly int $fromId,
        public readonly bool $fromIsBot,
        public readonly ?int $date,
        public readonly ?int $editDate,
        #[SensitiveParameter] private readonly ?string $text,
        #[SensitiveParameter] private readonly ?string $caption,
        public readonly array $attachmentTypes,
        public readonly ?int $replyToMessageId = null,
        public readonly ?string $callbackId = null,
        public readonly ?string $callbackData = null,
    ) {}

    /**
     * Returns null for anything the bot does not handle (other update types, malformed payloads).
     *
     * @param  array<mixed>  $payload
     */
    public static function fromArray(#[SensitiveParameter] array $payload): ?self
    {
        if (isset($payload[self::CALLBACK_QUERY])) {
            return self::fromCallback($payload);
        }

        $kind = isset($payload[self::MESSAGE]) ? self::MESSAGE : (isset($payload[self::EDITED_MESSAGE]) ? self::EDITED_MESSAGE : null);

        if ($kind === null || ! is_array($payload[$kind])) {
            return null;
        }

        $message = $payload[$kind];
        $chat = $message['chat'] ?? null;
        $from = $message['from'] ?? null;

        if (! is_int($payload['update_id'] ?? null)
            || ! is_int($message['message_id'] ?? null)
            || ! is_array($chat) || ! is_int($chat['id'] ?? null) || ! is_string($chat['type'] ?? null)
            || ! is_array($from) || ! is_int($from['id'] ?? null)) {
            return null;
        }

        return new self(
            updateId: $payload['update_id'],
            kind: $kind,
            chatId: $chat['id'],
            chatType: $chat['type'],
            messageId: $message['message_id'],
            fromId: $from['id'],
            fromIsBot: (bool) ($from['is_bot'] ?? false),
            date: is_int($message['date'] ?? null) ? $message['date'] : null,
            editDate: is_int($message['edit_date'] ?? null) ? $message['edit_date'] : null,
            text: is_string($message['text'] ?? null) ? $message['text'] : null,
            caption: is_string($message['caption'] ?? null) ? $message['caption'] : null,
            attachmentTypes: self::attachmentTypes($message),
            replyToMessageId: is_int($message['reply_to_message']['message_id'] ?? null) ? $message['reply_to_message']['message_id'] : null,
        );
    }

    /**
     * A button press. `messageId` is the message that carries the buttons.
     *
     * @param  array<mixed>  $payload
     */
    private static function fromCallback(array $payload): ?self
    {
        $query = $payload[self::CALLBACK_QUERY];
        $message = is_array($query) ? ($query['message'] ?? null) : null;

        if (! is_int($payload['update_id'] ?? null) || ! is_array($query)
            || ! is_string($query['id'] ?? null) || ! is_string($query['data'] ?? null)
            || ! is_array($query['from'] ?? null) || ! is_int($query['from']['id'] ?? null)
            || ! is_array($message) || ! is_int($message['message_id'] ?? null)
            || ! is_array($message['chat'] ?? null) || ! is_int($message['chat']['id'] ?? null) || ! is_string($message['chat']['type'] ?? null)) {
            return null;
        }

        return new self(
            updateId: $payload['update_id'],
            kind: self::CALLBACK_QUERY,
            chatId: $message['chat']['id'],
            chatType: $message['chat']['type'],
            messageId: $message['message_id'],
            fromId: $query['from']['id'],
            fromIsBot: (bool) ($query['from']['is_bot'] ?? false),
            date: null,
            editDate: null,
            text: null,
            caption: null,
            attachmentTypes: [],
            callbackId: $query['id'],
            callbackData: $query['data'],
        );
    }

    public function isCallback(): bool
    {
        return $this->kind === self::CALLBACK_QUERY;
    }

    /** Only one-to-one chats with a human are served; the chat id of a private chat equals the user id. */
    public function isPrivateUserChat(): bool
    {
        return $this->chatType === 'private' && ! $this->fromIsBot && $this->chatId === $this->fromId;
    }

    public function isEdit(): bool
    {
        return $this->kind === self::EDITED_MESSAGE;
    }

    public function idempotencyKey(): string
    {
        return "telegram:{$this->chatId}:{$this->messageId}";
    }

    /** Identifies one delivery of one version of a message (edits differ by edit_date). */
    public function versionKey(): string
    {
        if ($this->isCallback()) {
            return "callback:{$this->callbackId}";
        }

        return "{$this->idempotencyKey()}:".($this->editDate ?? 0);
    }

    public function isCommand(): bool
    {
        return $this->text !== null && str_starts_with($this->text, '/');
    }

    /**
     * @return array{name: string}|null
     */
    public function command(): ?array
    {
        if ($this->text === null || preg_match('~^/([A-Za-z0-9_]{1,32})(?:@[A-Za-z0-9_]{1,64})?(?:\s|$)~', $this->text, $m) !== 1) {
            return null;
        }

        return ['name' => strtolower($m[1])];
    }

    /**
     * What follows the command (`/task invoice` → "invoice"), trimmed and capped. Raw user text: it may only
     * be used as a search term, never stored or logged.
     */
    public function commandArgument(): ?string
    {
        if ($this->text === null || $this->command() === null) {
            return null;
        }

        $argument = trim((string) preg_replace('~^/[A-Za-z0-9_]{1,32}(?:@[A-Za-z0-9_]{1,64})?~', '', $this->text));

        return $argument === '' ? null : mb_substr($argument, 0, 60);
    }

    /** The user's words: text, or the caption of a media message. */
    public function content(): ?string
    {
        return $this->text ?? $this->caption;
    }

    public function hasAttachment(): bool
    {
        return $this->attachmentTypes !== [];
    }

    /** voice | image | other — which "not supported" message fits a media-only message. */
    public function unsupportedKind(): string
    {
        return match (true) {
            array_intersect($this->attachmentTypes, ['voice', 'audio', 'video_note']) !== [] => 'voice',
            array_intersect($this->attachmentTypes, ['photo', 'sticker', 'animation', 'video']) !== [] => 'image',
            default => 'other',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['updateId' => $this->updateId, 'kind' => $this->kind, 'text' => '[hidden]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('TelegramUpdate holds raw text and must not be serialized or queued.');
    }

    /**
     * @param  array<mixed>  $message
     * @return list<string>
     */
    private static function attachmentTypes(array $message): array
    {
        $types = [];

        foreach (['photo', 'voice', 'audio', 'document', 'video', 'video_note', 'sticker', 'animation', 'contact', 'location', 'poll'] as $type) {
            if (isset($message[$type])) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
