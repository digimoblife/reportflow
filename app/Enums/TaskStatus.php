<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Task lifecycle status (PRD §14). Transitions are governed by App\Domain\Tasks\TaskStatusTransition.
 */
enum TaskStatus: string
{
    use EnumValues;

    case Draft = 'draft';
    case Open = 'open';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
