<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Who caused a task event (PRD §49 task_events).
 */
enum EventActor: string
{
    use EnumValues;

    case Ai = 'ai';
    case User = 'user';
    case System = 'system';
}
