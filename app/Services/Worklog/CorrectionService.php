<?php

namespace App\Services\Worklog;

use App\Domain\Tasks\InvalidTaskStatusTransition;
use App\Domain\Tasks\TaskStatusTransition;
use App\Enums\CorrectionType;
use App\Enums\EventActor;
use App\Enums\OutcomeState;
use App\Enums\TaskStatus;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * The correction buttons (PRD §21): change the status, move the note to another task, move a new task to another
 * project. Every correction goes through TaskLifecycle (matrix, version, task_events) and is recorded in `corrections`
 * for the User Correction Rate. Only items that are still `applied` can be corrected.
 */
class CorrectionService
{
    public function __construct(
        private readonly TaskLifecycle $lifecycle,
        private readonly UndoService $undo,
        private readonly ProposalApplier $applier,
    ) {}

    /**
     * Statuses the task may move to right now (PRD §14 matrix).
     *
     * @return list<TaskStatus>
     */
    public function statusOptions(Task $task): array
    {
        return TaskStatusTransition::allowedFrom($task->status);
    }

    /**
     * @throws InvalidTaskStatusTransition
     * @throws StaleTaskException
     */
    public function changeStatus(InboundMessage $message, int $index, TaskStatus $to): ?OutcomeItem
    {
        return DB::transaction(function () use ($message, $index, $to): ?OutcomeItem {
            [$locked, $outcome, $item] = $this->lock($message, $index);
            $task = $item === null || $item->taskId === null ? null : Task::query()->lockForUpdate()->find($item->taskId);

            if ($item === null || $outcome === null || $task === null) {
                return null;
            }

            $from = $task->status;

            if (! $this->lifecycle->changeStatus($task, $to, EventActor::User, $locked->id)) {
                return $item;   // already there
            }

            Correction::query()->create([
                'inbound_message_id' => $locked->id,
                'correction_type' => CorrectionType::ChangeStatus,
                'before' => ['task_id' => $task->id, 'status' => $from->value],
                'after' => ['task_id' => $task->id, 'status' => $to->value],
            ]);

            $updated = $item->with(['status_to' => $to->value, 'status_from' => $item->createdTask ? null : ($item->statusFrom ?? $from->value), 'task_version' => $task->version, 'reopened' => false]);
            $this->store($locked, $outcome->replaceItem($updated));

            return $updated;
        });
    }

    /**
     * Files the note under another task (or a new one) by undoing the item as a step and applying its data again.
     *
     * @param  int|null  $targetTaskId  null = a new task in the same project
     */
    public function moveTask(InboundMessage $message, int $index, ?int $targetTaskId): ?OutcomeItem
    {
        return DB::transaction(function () use ($message, $index, $targetTaskId): ?OutcomeItem {
            [$locked, $outcome, $item] = $this->lock($message, $index);

            if ($item === null || $outcome === null || $item->pending === null) {
                return null;
            }

            if ($targetTaskId !== null && ($targetTaskId === $item->taskId || ! Task::query()->whereKey($targetTaskId)->exists())) {
                return null;
            }

            $this->undo->undo($locked, [$index], recordCorrection: false);

            $moved = $this->applier->applyPending($locked, $item->with(['state' => OutcomeState::Pending->value]), $targetTaskId, $item->projectId, $item->pendingDate);

            Correction::query()->create([
                'inbound_message_id' => $locked->id,
                'correction_type' => CorrectionType::MoveTask,
                'before' => ['task_id' => $item->taskId, 'created_task' => $item->createdTask],
                'after' => ['task_id' => $moved->taskId, 'created_task' => $moved->createdTask],
            ]);

            $message->outcome = InboundMessage::query()->whereKey($locked->id)->value('outcome');

            return $moved;
        });
    }

    /**
     * Moves the task that this message created to another project. Existing tasks are moved with moveTask instead.
     */
    public function changeProject(InboundMessage $message, int $index, int $projectId): ?OutcomeItem
    {
        return DB::transaction(function () use ($message, $index, $projectId): ?OutcomeItem {
            [$locked, $outcome, $item] = $this->lock($message, $index);
            $project = Project::query()->whereKey($projectId)->where('status', 'active')->first();
            $task = $item === null || $item->taskId === null ? null : Task::query()->lockForUpdate()->find($item->taskId);

            if ($item === null || $outcome === null || ! $item->createdTask || $project === null || $task === null) {
                return null;
            }

            $from = $task->project_id;
            $this->lifecycle->moveToProject($task, $project, EventActor::User, $locked->id);

            Correction::query()->create([
                'inbound_message_id' => $locked->id,
                'correction_type' => CorrectionType::ChangeProject,
                'before' => ['task_id' => $task->id, 'project_id' => $from],
                'after' => ['task_id' => $task->id, 'project_id' => $project->id],
            ]);

            $updated = $item->with(['project_id' => $project->id, 'task_version' => $task->version]);
            $this->store($locked, $outcome->replaceItem($updated));

            return $updated;
        });
    }

    /**
     * @return array{0: InboundMessage, 1: Outcome|null, 2: OutcomeItem|null} the locked message and its still-applied item
     */
    private function lock(InboundMessage $message, int $index): array
    {
        $locked = InboundMessage::query()->lockForUpdate()->findOrFail($message->id);
        $outcome = Outcome::fromArray($locked->outcome);
        $item = $outcome?->item($index);

        return [$locked, $outcome, $item !== null && $item->state === OutcomeState::Applied ? $item : null];
    }

    private function store(InboundMessage $message, Outcome $outcome): void
    {
        InboundMessage::query()->whereKey($message->id)->update(['outcome' => $outcome->toArray()]);
        $message->outcome = $outcome->toArray();
    }
}
