<?php

namespace App\Services\Telegram;

use App\Enums\OutcomeState;
use App\Services\Worklog\PendingAnswerService;

/**
 * Telegram buttons for clarification questions (PRD §13, §59, §69). The answer itself is PendingAnswerService (shared with
 * the dashboard); this class only turns the result into the edited Telegram bubble. A second press only answers "already answered".
 */
class PendingAnswerHandler
{
    public function __construct(
        private readonly PendingAnswerService $answers,
        private readonly TelegramMessenger $messenger,
        private readonly ConfirmationComposer $composer,
        private readonly BotMessages $messages,
        private readonly LanguageDetector $languages,
    ) {}

    public function register(CallbackRouter $router): void
    {
        foreach (PendingAnswerService::ACTIONS as $action) {
            $router->on($action, fn (CallbackContext $context) => $this->handle($context));
        }
    }

    public function handle(CallbackContext $context): void
    {
        $language = $this->languages->detect($context->message->text, $context->language);
        $timezone = $context->user->timezone;

        $result = $this->answers->answer($context->message, $context->data->item ?? -1, $context->data->action, $context->data->arg, $timezone);

        if ($result === null) {
            $this->messenger->tryAnswer($context->callbackId(), $this->messages->get('callback.answered', $language));

            return;
        }

        $written = $result['written'];
        $this->messenger->tryAnswer($context->callbackId());

        if ($written->state === OutcomeState::Applied) {
            $text = $this->messages->get('worklog.recorded', $language)."\n\n".$this->composer->block($written, $language, $timezone);
            $keyboard = $this->composer->correctionKeyboard($context->message, [$written], $language);
        } else {
            $text = $this->messages->get('question.cancelled', $language);
            $keyboard = [];
        }

        try {
            $this->messenger->edit($context->chatId(), $context->bubbleMessageId(), $text, $keyboard);
        } catch (TelegramApiException) {
            $this->messenger->trySend($context->chatId(), $text, null, $keyboard === [] ? null : $keyboard);
        }
    }
}
