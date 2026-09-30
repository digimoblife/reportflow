<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Audit trail event types for tasks (PRD §47, §49 task_events).
 */
enum TaskEventType: string
{
    use EnumValues;

    case Created = 'created';
    case StatusChanged = 'status_changed';
    case TitleChanged = 'title_changed';
    case Moved = 'moved';
    case Merged = 'merged';
    case Reopened = 'reopened';
    case Undone = 'undone';
}
