<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Role of a person on a task (PRD §49 task_people).
 */
enum TaskPersonRole: string
{
    use EnumValues;

    case Requester = 'requester';
    case Assignee = 'assignee';
    case Stakeholder = 'stakeholder';
}
