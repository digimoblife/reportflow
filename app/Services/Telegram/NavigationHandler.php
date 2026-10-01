<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Models\User;
use App\Services\Worklog\ReprocessService;

/**
 * Buttons of M4e: list paging and picking (`v:` payloads) and reprocessing a stored message (`redo`, `keep`).
 * Reprocessing goes through ReprocessService (undo what the old run wrote, then the normal pipeline); stale question
 * bubbles of the old run are closed so they cannot act on the new outcome.
 */
class NavigationHandler
{
    public function __construct(
        private readonly ListingCommands $listing,
        private readonly ReprocessService $reprocess,
        private readonly TelegramMessenger $messenger,
        private readonly BotMessages $messages,
    ) {}

    public function register(CallbackRouter $router): void
    {
        $router->onView(fn (ViewData $data, TelegramUpdate $update, User $user, Language $language) => $this->view($data, $update, $user, $language));
        $router->on('redo', fn (CallbackContext $context) => $this->redo($context));
        $router->on('keep', fn (CallbackContext $context) => $this->keep($context));
    }

    private function view(ViewData $data, TelegramUpdate $update, User $user, Language $language): void
    {
        $tz = $user->timezone;
        $view = match ($data->view) {
            'tasks' => $this->listing->tasks($data->page, $language, $tz),
            'task' => $data->ref === null ? null : $this->listing->taskById($data->ref, $language, $tz),
            'project' => $data->ref === null ? null : $this->listing->projectById($data->ref, $language, $tz),
            default => null,
        };

        $this->messenger->tryAnswer((string) $update->callbackId);

        if ($view === null) {
            return;
        }

        try {
            $this->messenger->edit($update->chatId, $update->messageId, $view['text'], $view['keyboard']);
        } catch (TelegramApiException $e) {
            if (! $e->messageNotModified()) {
                $this->messenger->trySend($update->chatId, $view['text'], null, $view['keyboard'] === [] ? null : $view['keyboard']);
            }
        }
    }

    private function redo(CallbackContext $context): void
    {
        $language = $context->language;
        $message = $context->message;
        $replyId = $message->reply_message_id;
        $result = $this->reprocess->reprocess($message);

        if ($result === null) {
            $this->messenger->tryAnswer($context->callbackId(), $this->messages->get('reprocess.busy', $language));

            return;
        }

        $this->messenger->tryAnswer($context->callbackId(), $this->messages->get('reprocess.started', $language));
        $chatId = (int) ($message->telegram_chat_id ?? $context->chatId());

        foreach ($result['question_message_ids'] as $id) {
            $this->tryEdit($chatId, $id, $this->messages->get('reprocess.superseded', $language));
        }

        $ack = $this->messages->get('worklog.ack', $language);

        // The old confirmation (or the "failed" notice) becomes the acknowledgement; the delivery job edits it again.
        if ($replyId !== null && $this->tryEdit($chatId, $replyId, $ack)) {
            return;
        }

        $newId = $this->messenger->trySend($chatId, $ack, $message->telegram_message_id);

        if ($newId !== null) {
            $message->newQuery()->whereKey($message->id)->update(['reply_message_id' => $newId]);
        }
    }

    private function keep(CallbackContext $context): void
    {
        $this->messenger->tryAnswer($context->callbackId());
        $this->tryEdit($context->chatId(), $context->bubbleMessageId(), $this->messages->get('reprocess.kept', $context->language));
    }

    private function tryEdit(int $chatId, int $messageId, string $text): bool
    {
        try {
            $this->messenger->edit($chatId, $messageId, $text, []);

            return true;
        } catch (TelegramApiException $e) {
            return $e->messageNotModified();
        }
    }
}
