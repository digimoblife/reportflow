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

    /** @var (Closure(ViewData, TelegramUpdate, User, Language): void)|null */
    private ?Closure $viewHandler = null;

    /** @var (Closure(ReminderCallback, TelegramUpdate, User, Language): void)|null */
    private ?Closure $reminderHandler = null;

    /** @var (Closure(ReportCallback, TelegramUpdate, User, Language): void)|null */
    private ?Closure $reportHandler = null;

    /** @var (Closure(ReportStartCallback, TelegramUpdate, User, Language): void)|null */
    private ?Closure $reportStartHandler = null;

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
     * @param  Closure(ViewData, TelegramUpdate, User, Language): void  $handler
     */
    public function onView(Closure $handler): void
    {
        $this->viewHandler = $handler;
    }

    /**
     * @param  Closure(ReminderCallback, TelegramUpdate, User, Language): void  $handler
     */
    public function onReminder(Closure $handler): void
    {
        $this->reminderHandler = $handler;
    }

    /**
     * @param  Closure(ReportCallback, TelegramUpdate, User, Language): void  $handler
     */
    public function onReport(Closure $handler): void
    {
        $this->reportHandler = $handler;
    }

    /**
     * @param  Closure(ReportStartCallback, TelegramUpdate, User, Language): void  $handler
     */
    public function onReportStart(Closure $handler): void
    {
        $this->reportStartHandler = $handler;
    }

    /**
     * Must run inside the user's UserContext.
     */
    public function handle(TelegramUpdate $update, User $user, Language $language): void
    {
        $callbackId = (string) $update->callbackId;
        $start = ReportStartCallback::parse((string) $update->callbackData);

        if ($start !== null && $this->reportStartHandler !== null) {
            ($this->reportStartHandler)($start, $update, $user, $language);

            return;
        }

        $report = ReportCallback::parse((string) $update->callbackData);

        if ($report !== null && $this->reportHandler !== null) {
            ($this->reportHandler)($report, $update, $user, $language);

            return;
        }

        $reminder = ReminderCallback::parse((string) $update->callbackData);

        if ($reminder !== null && $this->reminderHandler !== null) {
            ($this->reminderHandler)($reminder, $update, $user, $language);

            return;
        }

        $view = ViewData::parse((string) $update->callbackData);

        if ($view !== null && $this->viewHandler !== null) {
            ($this->viewHandler)($view, $update, $user, $language);

            return;
        }

        $data = CallbackData::parse((string) $update->callbackData);
        $message = $data === null ? null : InboundMessage::query()->find($data->inboundMessageId);

        if ($data === null || $message === null || ! isset($this->handlers[$data->action])) {
            $this->messenger->tryAnswer($callbackId, $this->messages->get('callback.expired', $language));

            return;
        }

        ($this->handlers[$data->action])(new CallbackContext($update, $user, $language, $data, $message));
    }
}
