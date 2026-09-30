<?php

namespace App\Services\Telegram;

use App\Domain\Tasks\InvalidTaskStatusTransition;
use App\Enums\Language;
use App\Enums\OutcomeState;
use App\Enums\TaskStatus;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Task;
use App\Services\Worklog\CorrectionService;
use App\Services\Worklog\Outcome;
use App\Services\Worklog\OutcomeItem;
use App\Services\Worklog\StaleTaskException;
use App\Services\Worklog\UndoService;

/**
 * The correction buttons under a confirmation (PRD §21): Undo, Pindah Task, Ubah Status, Ganti Project.
 * Pickers replace the message text in place; "Kembali" restores the confirmation. Every change goes through
 * UndoService / CorrectionService (matrix, versions, task_events, corrections).
 */
class CorrectionHandler
{
    private const ACTIONS = ['undo', 'move', 'mvto', 'status', 'setst', 'project', 'setpr', 'back'];

    private const ACTIVE = [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Waiting, TaskStatus::Blocked];

    public function __construct(
        private readonly UndoService $undo,
        private readonly CorrectionService $corrections,
        private readonly TelegramMessenger $messenger,
        private readonly ConfirmationComposer $composer,
        private readonly BotMessages $messages,
        private readonly LanguageDetector $languages,
    ) {}

    public function register(CallbackRouter $router): void
    {
        foreach (self::ACTIONS as $action) {
            $router->on($action, fn (CallbackContext $context) => $this->handle($context));
        }
    }

    public function handle(CallbackContext $context): void
    {
        $language = $this->languages->detect($context->message->text, $context->language);
        $message = InboundMessage::query()->findOrFail($context->message->id);
        $outcome = Outcome::fromArray($message->outcome);
        $item = $context->data->item === null ? null : $outcome?->item($context->data->item);
        $toast = fn (string $key, array $replace = []) => $this->messenger->tryAnswer($context->callbackId(), $this->messages->get($key, $language, $replace));
        $action = $context->data->action;

        if ($outcome === null) {
            $toast('callback.expired');

            return;
        }

        if ($action === 'back') {
            $this->show($context, $message, $language);
            $this->messenger->tryAnswer($context->callbackId());

            return;
        }

        if ($action === 'undo') {
            $this->handleUndo($context, $message, $outcome, $language);

            return;
        }

        if ($item === null || $item->state !== OutcomeState::Applied) {
            $toast('callback.answered');

            return;
        }

        try {
            match ($action) {
                'move' => $this->openPicker($context, $message, $item, $language, 'task'),
                'status' => $this->openPicker($context, $message, $item, $language, 'status'),
                'project' => $item->createdTask ? $this->openPicker($context, $message, $item, $language, 'project') : $toast('correction.project_new_only'),
                'mvto' => $this->apply($context, $message, $language, $this->corrections->moveTask($message, $item->index, $context->data->arg === 'new' ? null : (int) $context->data->arg)),
                'setst' => $this->apply($context, $message, $language, $this->setStatus($message, $item, (string) $context->data->arg)),
                'setpr' => $this->apply($context, $message, $language, $this->corrections->changeProject($message, $item->index, (int) $context->data->arg)),
                default => null,
            };
        } catch (InvalidTaskStatusTransition) {
            $toast('correction.not_possible');
        } catch (StaleTaskException) {
            $toast('correction.stale');
        }
    }

    private function handleUndo(CallbackContext $context, InboundMessage $message, Outcome $outcome, Language $language): void
    {
        $undone = $this->undo->undo($message, $context->data->item === null ? null : [$context->data->item]);

        if ($undone === []) {
            $this->messenger->tryAnswer($context->callbackId(), $this->messages->get('callback.answered', $language));

            return;
        }

        $partial = array_filter($undone, fn (array $u): bool => $u['partial']) !== [];
        $this->messenger->tryAnswer($context->callbackId(), $partial ? $this->messages->get('undo.partial', $language) : null);
        $this->show($context, $message->refresh(), $language, $partial ? $this->messages->get('undo.partial', $language) : null);
    }

    private function setStatus(InboundMessage $message, OutcomeItem $item, string $status): ?OutcomeItem
    {
        $to = TaskStatus::tryFrom($status);

        return $to === null ? null : $this->corrections->changeStatus($message, $item->index, $to);
    }

    private function apply(CallbackContext $context, InboundMessage $message, Language $language, ?OutcomeItem $result): void
    {
        if ($result === null) {
            $this->messenger->tryAnswer($context->callbackId(), $this->messages->get('correction.not_possible', $language));

            return;
        }

        $this->messenger->tryAnswer($context->callbackId(), $this->messages->get('correction.done', $language));
        $this->show($context, $message->refresh(), $language);
    }

    private function openPicker(CallbackContext $context, InboundMessage $message, OutcomeItem $item, Language $language, string $kind): void
    {
        $task = $item->taskId === null ? null : Task::query()->find($item->taskId);

        $view = match (true) {
            $kind === 'task' => $this->composer->pickTask($message, $item, $language, $this->taskChoices($item)),
            $kind === 'status' && $task !== null => $this->composer->pickStatus($message, $item, $language, $task, $this->corrections->statusOptions($task)),
            $kind === 'project' => $this->composer->pickProject($message, $item, $language, $this->projectChoices($item)),
            default => null,
        };

        $this->messenger->tryAnswer($context->callbackId());

        if ($view !== null) {
            $this->edit($context, $view['text'], $view['keyboard']);
        }
    }

    /**
     * @return list<Task>
     */
    private function taskChoices(OutcomeItem $item): array
    {
        $tasks = Task::query()->whereIn('status', self::ACTIVE)->where('id', '!=', $item->taskId ?? 0)->get();

        return array_slice(array_values($tasks->sortBy([
            fn (Task $a, Task $b): int => ($b->project_id === $item->projectId) <=> ($a->project_id === $item->projectId),
            fn (Task $a, Task $b): int => (int) $b->last_activity_at?->timestamp <=> (int) $a->last_activity_at?->timestamp,
        ])->all()), 0, 8);
    }

    /**
     * @return list<Project>
     */
    private function projectChoices(OutcomeItem $item): array
    {
        return array_values(Project::query()->where('status', 'active')->where('id', '!=', $item->projectId ?? 0)->orderBy('id')->get()->all());
    }

    private function show(CallbackContext $context, InboundMessage $message, Language $language, ?string $note = null): void
    {
        $view = $this->composer->view($message, $context->bubbleMessageId(), $language, $context->user->timezone);

        if ($view !== null) {
            $this->edit($context, $note === null ? $view['text'] : $view['text']."\n\n".$note, $view['keyboard']);
        }
    }

    /**
     * @param  list<list<array{text: string, callback_data: string}>>  $keyboard
     */
    private function edit(CallbackContext $context, string $text, array $keyboard): void
    {
        try {
            $this->messenger->edit($context->chatId(), $context->bubbleMessageId(), $text, $keyboard);
        } catch (TelegramApiException $e) {
            if (! $e->messageNotModified()) {
                $this->messenger->trySend($context->chatId(), $text, null, $keyboard === [] ? null : $keyboard);
            }
        }
    }
}
