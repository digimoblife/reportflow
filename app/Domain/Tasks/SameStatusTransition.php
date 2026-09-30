<?php

namespace App\Domain\Tasks;

use App\Enums\TaskStatus;

/**
 * Thrown when a "transition" keeps the same status. Callers should treat this as a no-op.
 */
final class SameStatusTransition extends InvalidTaskStatusTransition
{
    public function __construct(TaskStatus $status)
    {
        parent::__construct($status, $status, "Task is already in status [{$status->value}]; this is not a transition.");
    }
}
