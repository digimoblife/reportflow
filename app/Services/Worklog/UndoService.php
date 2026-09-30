<?php

namespace App\Services\Worklog;

use App\Enums\CorrectionType;
use App\Enums\EventActor;
use App\Enums\OutcomeState;
use App\Enums\TaskEventType;
use App\Models\Activity;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Support\Facades\DB;

/**
 * Undo for everything an inbound message wrote (PRD §21; CLAUDE.md rule 5). History is never deleted:
 *
 * - the activity is soft-deleted;
 * - a task that the message created is soft-deleted when nothing else was recorded on it;
 * - a status change (and last_activity_at / started_at / completed_at) is restored from the snapshot taken before
 *   the message touched the task, but ONLY if the task has not been modified since (tasks.version still matches
 *   the one recorded in the outcome); otherwise just the note is removed and the item is reported as "partial";
 * - `task_events` of type `undone` and a `corrections(undo)` row are written.
 *
 * Idempotent: only items still `applied` are touched.
 */
class UndoService
{
    public function __construct(private readonly TaskLifecycle $lifecycle) {}

    /**
     * @param  list<int>|null  $indexes  item indexes to undo; null = every applied item
     * @param  bool  $recordCorrection  false when a correction (e.g. move) undoes an item as one of its own steps
     * @return list<array{item: OutcomeItem, partial: bool}> what was undone
     */
    public function undo(InboundMessage $message, ?array $indexes = null, bool $recordCorrection = true): array
    {
        return DB::transaction(function () use ($message, $indexes, $recordCorrection): array {
            $locked = InboundMessage::query()->lockForUpdate()->findOrFail($message->id);
            $outcome = Outcome::fromArray($locked->outcome);

            if ($outcome === null) {
                return [];
            }

            $undone = [];

            foreach ($outcome->applied() as $item) {
                if ($indexes !== null && ! in_array($item->index, $indexes, true)) {
                    continue;
                }

                $partial = $this->undoItem($locked, $item);
                $marked = $item->with(['state' => OutcomeState::Undone->value]);
                $outcome = $outcome->replaceItem($marked);
                $undone[] = ['item' => $marked, 'partial' => $partial];

                if ($recordCorrection) {
                    Correction::query()->create([
                        'inbound_message_id' => $locked->id,
                        'correction_type' => CorrectionType::Undo,
                        'before' => ['item' => $item->index, 'task_id' => $item->taskId, 'activity_id' => $item->activityId, 'created_task' => $item->createdTask, 'status_from' => $item->statusFrom, 'status_to' => $item->statusTo],
                        'after' => ['partial' => $partial],
                    ]);
                }
            }

            if ($undone !== []) {
                InboundMessage::query()->whereKey($locked->id)->update(['outcome' => $outcome->toArray()]);
                $message->outcome = $outcome->toArray();
            }

            return $undone;
        });
    }

    /**
     * @return bool true when only the note could be removed (the task changed since)
     */
    private function undoItem(InboundMessage $message, OutcomeItem $item): bool
    {
        if ($item->activityId !== null) {
            Activity::query()->whereKey($item->activityId)->delete();
        }

        $task = $item->taskId === null ? null : Task::query()->lockForUpdate()->find($item->taskId);

        if ($task === null) {
            return false;
        }

        $unchanged = $item->taskVersion === $task->version;

        if ($item->createdTask) {
            $others = Activity::query()->where('task_id', $task->id)->count();

            if ($others > 0) {
                return false;   // the task grew beyond this note: keep it
            }

            $createdEvent = TaskEvent::query()->where('task_id', $task->id)->where('event_type', TaskEventType::Created)->value('id');
            $this->lifecycle->discard($task, EventActor::User, $message->id, $createdEvent === null ? null : (int) $createdEvent);

            return false;
        }

        if (! $unchanged) {
            return $item->restore !== null && $this->statusTouched($item);
        }

        if ($item->restore !== null && $this->statusTouched($item)) {
            $event = TaskEvent::query()->where('task_id', $task->id)->where('inbound_message_id', $message->id)
                ->whereIn('event_type', [TaskEventType::StatusChanged, TaskEventType::Reopened])->orderByDesc('id')->value('id');
            $this->lifecycle->restore($task, $item->restore, EventActor::User, $message->id, $event === null ? null : (int) $event, $item->taskVersion);
        } elseif ($item->restore !== null) {
            // No status change: put last_activity_at back (bumps the version, keeps the audit trail through the soft-deleted activity).
            $this->lifecycle->restore($task, $item->restore + ['status' => $task->status->value], EventActor::User, $message->id, null, $item->taskVersion);
        }

        return false;
    }

    private function statusTouched(OutcomeItem $item): bool
    {
        return $item->statusTo !== null;
    }
}
