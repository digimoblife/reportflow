<?php

namespace App\Services\Worklog;

use App\Enums\CorrectionType;
use App\Enums\InboundMessageStatus;
use App\Enums\OutcomeState;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Answers a clarification question (PRD §13, §59, §69) for any channel: yes / new task / pick another task /
 * choose a project / keep or reset an old date / cancel. Only the answered item is written; the other items of the
 * message stay as they are (PRD §10). Answers that change the proposal are recorded in `corrections`. A second answer
 * finds the item no longer pending and does nothing (so an answer from Telegram and one from the dashboard cannot both win).
 */
class PendingAnswerService
{
    public const ACTIONS = ['yes', 'new', 'pick', 'proj', 'date', 'skip'];

    public function __construct(private readonly ProposalApplier $applier) {}

    /**
     * @return array{item: OutcomeItem, written: OutcomeItem}|null null when the item is not pending (already answered) or the answer is invalid
     */
    public function answer(InboundMessage $message, int $index, string $action, ?string $arg, string $timezone): ?array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return null;
        }

        /** @var array{item: OutcomeItem, written: OutcomeItem}|null $result */
        $result = DB::transaction(function () use ($message, $index, $action, $arg, $timezone): ?array {
            $locked = InboundMessage::query()->lockForUpdate()->findOrFail($message->id);
            $outcome = Outcome::fromArray($locked->outcome);
            $item = $outcome?->item($index);

            if ($outcome === null || $item === null || $item->state !== OutcomeState::Pending) {
                return null;
            }

            $written = $this->apply($locked, $outcome, $item, $action, $arg, $timezone);

            if ($written === null) {
                return null;
            }

            $updated = Outcome::fromArray($locked->fresh()?->outcome);

            if ($updated !== null && ! $updated->hasPending() && $locked->status === InboundMessageStatus::NeedsClarification) {
                InboundMessage::query()->whereKey($locked->id)->update(['status' => InboundMessageStatus::Processed->value]);
            }

            return ['item' => $item, 'written' => $written];
        });

        return $result;
    }

    private function apply(InboundMessage $message, Outcome $outcome, OutcomeItem $item, string $action, ?string $arg, string $timezone): ?OutcomeItem
    {
        switch ($action) {
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
                $today = now($timezone)->format('Y-m-d');
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
