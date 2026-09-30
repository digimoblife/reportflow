<?php

namespace App\Domain\Tasks;

use App\Enums\TaskStatus;

/**
 * Task status transition matrix (PRD §14).
 *
 * Framework-free on purpose: it depends only on the TaskStatus enum and its own exceptions.
 * A transition to the same status is never a valid transition; callers should treat
 * an unchanged status as a no-op (no task_event) instead of calling assert.
 * Deleting a Draft is a soft delete, not a status, so it is not part of this matrix.
 */
final class TaskStatusTransition
{
    /**
     * @var array<string, list<TaskStatus>>
     */
    private const MATRIX = [
        'draft' => [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Waiting, TaskStatus::Blocked, TaskStatus::Completed],
        'open' => [TaskStatus::InProgress, TaskStatus::Waiting, TaskStatus::Blocked, TaskStatus::Completed, TaskStatus::Cancelled],
        'in_progress' => [TaskStatus::Waiting, TaskStatus::Blocked, TaskStatus::Completed, TaskStatus::Cancelled],
        'waiting' => [TaskStatus::InProgress, TaskStatus::Blocked, TaskStatus::Completed, TaskStatus::Cancelled],
        'blocked' => [TaskStatus::InProgress, TaskStatus::Waiting, TaskStatus::Completed, TaskStatus::Cancelled],
        'completed' => [TaskStatus::InProgress],
        'cancelled' => [TaskStatus::Open],
    ];

    public static function canTransition(TaskStatus $from, TaskStatus $to): bool
    {
        return in_array($to, self::allowedFrom($from), true);
    }

    /**
     * @return list<TaskStatus>
     */
    public static function allowedFrom(TaskStatus $from): array
    {
        return self::MATRIX[$from->value];
    }

    /**
     * @throws SameStatusTransition when $from and $to are equal
     * @throws InvalidTaskStatusTransition when the matrix does not allow the transition
     */
    public static function assertCanTransition(TaskStatus $from, TaskStatus $to): void
    {
        if ($from === $to) {
            throw new SameStatusTransition($from);
        }

        if (! self::canTransition($from, $to)) {
            throw new InvalidTaskStatusTransition($from, $to);
        }
    }
}
