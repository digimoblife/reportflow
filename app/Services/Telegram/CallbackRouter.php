<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Models\InboundMessage;
use App\Models\User;
use Closure;

/**
 * Routes inline button presses (M4). The pressing user must own the inbound message the button belongs to
 * (user-scoped lookup, CLAUDE.md rule 11); anything else is answered with a neutral "expired" toast and ignored.
 * Handlers are registered per action by the services that own them (undo, corrections, confirmations, ...).
 */
class CallbackRouter
{
    /** @var array<string, Closure(CallbackContext): void> */
    private array $handlers = [];

    public function __construct(
        private readonly TelegramMessenger $messenger,
        private readonly BotMessages $messages,
    ) {}

    /**
     * @param  Closure(CallbackContext): void  $handler
     */
    public function on(string $action, Closure $handler): void
    {
        $this->handlers[$action] = $handler;
    }

    /**
     * Must run inside the user's UserContext.
     */
    public function handle(TelegramUpdate $update, User $user, Language $language): void
    {
        $callbackId = (string) $update->callbackId;
        $data = CallbackData::parse((string) $update->callbackData);
        $message = $data === null ? null : InboundMessage::query()->find($data->inboundMessageId);

        if ($data === null || $message === null || ! isset($this->handlers[$data->action])) {
            $this->messenger->tryAnswer($callbackId, $this->messages->get('callback.expired', $language));

            return;
        }

        ($this->handlers[$data->action])(new CallbackContext($update, $user, $language, $data, $message));
    }
}
