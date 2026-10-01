<?php

namespace App\Services\Telegram;

use App\Enums\CorrectionType;
use App\Enums\InboundMessageStatus;
use App\Enums\OutcomeState;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Services\Worklog\Outcome;
use App\Services\Worklog\OutcomeItem;
use App\Services\Worklog\ProposalApplier;
use Illuminate\Support\Facades\DB;

/**
 * Handles the answer buttons of clarification questions (PRD §13, §59, §69): yes / new task / pick another task /
 * choose a project / keep or reset an old date / cancel. Only the answered item is written; the other items of
 * the message stay as they are (PRD §10). Answers that change the proposal are recorded in `corrections`.
 * A second press finds the item no longer pending and only answers "already answered".
 */
class PendingAnswerHandler
{
    private const ACTIONS = ['yes', 'new', 'pick', 'proj', 'date', 'skip'];

    public function __construct(
        private readonly ProposalApplier $applier,
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
        $timezone = $context->user->timezone;

        /** @var array{0: OutcomeItem|null, 1: OutcomeItem|null} $result */
        $result = DB::transaction(function () use ($context): array {
            $message = InboundMessage::query()->lockForUpdate()->findOrFail($context->message->id);
            $outcome = Outcome::fromArray($message->outcome);
            $item = $outcome?->item($context->data->item ?? -1);

            if ($outcome === null || $item === null || $item->state !== OutcomeState::Pending) {
                return [null, null];
            }

            $written = $this->answer($context, $message, $outcome, $item);
            $updated = Outcome::fromArray($message->fresh()?->outcome);

            if ($updated !== null && ! $updated->hasPending() && $message->status === InboundMessageStatus::NeedsClarification) {
                InboundMessage::query()->whereKey($message->id)->update(['status' => InboundMessageStatus::Processed->value]);
            }

            return [$item, $written];
        });

        [$item, $written] = $result;

        if ($item === null || $written === null) {
            $this->messenger->tryAnswer($context->callbackId(), $this->messages->get('callback.answered', $language));

            return;
        }

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

    private function answer(CallbackContext $context, InboundMessage $message, Outcome $outcome, OutcomeItem $item): ?OutcomeItem
    {
        $arg = $context->data->arg;

        switch ($context->data->action) {
            case 'skip':
                $skipped = $item->with(['state' => OutcomeState::Skipped->value, 'pending' => null]);
                InboundMessage::query()->whereKey($message->id)->update(['outcome' => $outcome->replaceItem($skipped)->toArray()]);
                $message->outcome = $outcome->replaceItem($skipped)->toArray();

                return $skipped;

            case 'yes':
                return $item->question === 'match' ? $this->applier->applyPending($message, $item, $item->taskId) : null;

            case 'new':
                if ($item->question !== 'match') {
                    return null;
                }
                $this->correction($message, CorrectionType::MoveTask, ['task_id' => $item->taskId], ['task_id' => null, 'new_task' => true]);

                return $this->applier->applyPending($message, $item, null, $item->projectId);

            case 'pick':
                $picked = is_numeric($arg) ? (int) $arg : 0;
                if ($item->question !== 'match' || ! in_array($picked, $item->options, true)) {
                    return null;
                }
                $this->correction($message, CorrectionType::MoveTask, ['task_id' => $item->taskId], ['task_id' => $picked]);

                return $this->applier->applyPending($message, $item, $picked);

            case 'proj':
                $projectId = is_numeric($arg) ? (int) $arg : 0;
                if ($item->question !== 'project' || ! Project::query()->whereKey($projectId)->where('status', 'active')->exists()) {
                    return null;
                }
                $this->correction($message, CorrectionType::ChangeProject, ['project_id' => null], ['project_id' => $projectId]);

                return $this->applier->applyPending($message, $item, null, $projectId);

            case 'date':
                if ($item->question !== 'date' || ! in_array($arg, ['keep', 'today'], true)) {
                    return null;
                }
                $today = now($context->user->timezone)->format('Y-m-d');
                if ($arg === 'today') {
                    $this->correction($message, CorrectionType::EditDate, ['date' => $item->pendingDate], ['date' => $today]);
                }

                return $this->applier->applyPending($message, $item, $item->taskId, $item->projectId, $arg === 'today' ? $today : null);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function correction(InboundMessage $message, CorrectionType $type, array $before, array $after): void
    {
        Correction::query()->create([
            'inbound_message_id' => $message->id,
            'correction_type' => $type,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
