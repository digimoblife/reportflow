<?php

namespace App\Services\Worklog;

use App\Domain\Tasks\InvalidTaskStatusTransition;
use App\Domain\Tasks\TaskStatusTransition;
use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The only place that creates tasks or changes their status (CLAUDE.md rule 4, PRD §14, §23, §47).
 *
 * - Status changes go through TaskStatusTransition; a change to the same status is a no-op (no event).
 * - Every change bumps tasks.version and is guarded by it: a concurrent change makes the write fail with
 *   StaleTaskException instead of silently overwriting.
 * - Every change writes a task_events row with the full snapshot shape defined in docs/DECISIONS.md (M1).
 */
class TaskLifecycle
{
    public function create(Project $project, string $title, TaskStatus $status, EventActor $actor, ?int $inboundMessageId = null): Task
    {
        $now = Carbon::now('UTC');

        $task = Task::query()->create([
            'project_id' => $project->id,
            'title' => mb_substr(trim($title), 0, 200),
            'status' => $status,
            'waiting_reason' => null,
            'priority' => TaskPriority::Normal,
            'started_at' => $status === TaskStatus::InProgress ? $now : null,
            'completed_at' => $status === TaskStatus::Completed ? $now : null,
            'version' => 1,
        ]);

        $this->event($task, TaskEventType::Created, null, [
            'project_id' => $task->project_id,
            'title' => $task->title,
            'status' => $status->value,
            'waiting_reason' => null,
        ], $actor, $inboundMessageId);

        return $task;
    }

    /**
     * @return bool false when the task already has that status (nothing written)
     *
     * @throws InvalidTaskStatusTransition
     * @throws StaleTaskException
     */
    public function changeStatus(Task $task, TaskStatus $to, EventActor $actor, ?int $inboundMessageId = null, ?int $expectedVersion = null): bool
    {
        $from = $task->status;

        if ($from === $to) {
            return false;
        }

        TaskStatusTransition::assertCanTransition($from, $to);

        $now = Carbon::now('UTC');
        $updates = [
            'status' => $to,
            'waiting_reason' => null,
            'started_at' => $task->started_at ?? ($to === TaskStatus::InProgress ? $now : null),
            'completed_at' => $to === TaskStatus::Completed ? $now : null,
        ];

        $before = ['status' => $from->value, 'waiting_reason' => $task->waiting_reason?->value];
        $this->save($task, $updates, $expectedVersion);

        $reopened = ($from === TaskStatus::Completed && $to === TaskStatus::InProgress) || ($from === TaskStatus::Cancelled && $to === TaskStatus::Open);
        $this->event($task, $reopened ? TaskEventType::Reopened : TaskEventType::StatusChanged, $before, ['status' => $to->value, 'waiting_reason' => null], $actor, $inboundMessageId);

        return true;
    }

    /**
     * Records that work happened on the task at $when (keeps the newest date). Bumps the version.
     */
    public function touchActivity(Task $task, CarbonImmutable $when, ?int $expectedVersion = null): void
    {
        $at = $when->utc();
        $newest = $task->last_activity_at === null || $at->gt($task->last_activity_at) ? $at : $task->last_activity_at;

        $this->save($task, ['last_activity_at' => $newest], $expectedVersion);
    }

    /**
     * @param  array<string, mixed>  $updates
     *
     * @throws StaleTaskException
     */
    private function save(Task $task, array $updates, ?int $expectedVersion): void
    {
        $expected = $expectedVersion ?? $task->version;

        $affected = Task::query()->whereKey($task->id)->where('version', $expected)->update($updates + ['version' => $expected + 1]);

        if ($affected === 0) {
            throw new StaleTaskException($task->id);
        }

        $task->forceFill($updates + ['version' => $expected + 1])->syncOriginal();
    }

    /**
     * @param  array<string, mixed>|null  $from
     * @param  array<string, mixed>|null  $to
     */
    private function event(Task $task, TaskEventType $type, ?array $from, ?array $to, EventActor $actor, ?int $inboundMessageId): void
    {
        TaskEvent::query()->create([
            'task_id' => $task->id,
            'event_type' => $type,
            'from_value' => $from,
            'to_value' => $to,
            'actor' => $actor,
            'inbound_message_id' => $inboundMessageId,
        ]);
    }
}
