<?php

namespace App\Services\Worklog;

use RuntimeException;

/**
 * The task changed since the caller read it (tasks.version, PRD §23 optimistic locking).
 */
final class StaleTaskException extends RuntimeException
{
    public function __construct(public readonly int $taskId)
    {
        parent::__construct("Task {$taskId} was modified by someone else; reload and retry.");
    }
}
