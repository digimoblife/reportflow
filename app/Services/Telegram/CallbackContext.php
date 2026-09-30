<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Models\InboundMessage;
use App\Models\User;

/**
 * Everything a button handler needs; the message is already known to belong to the user.
 */
final readonly class CallbackContext
{
    public function __construct(
        public TelegramUpdate $update,
        public User $user,
        public Language $language,
        public CallbackData $data,
        public InboundMessage $message,
    ) {}

    public function chatId(): int
    {
        return $this->update->chatId;
    }

    /** The message that carries the pressed button. */
    public function bubbleMessageId(): int
    {
        return $this->update->messageId;
    }

    public function callbackId(): string
    {
        return (string) $this->update->callbackId;
    }
}
