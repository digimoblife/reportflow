<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Correction kinds (PRD §21, §49 corrections).
 */
enum CorrectionType: string
{
    use EnumValues;

    case Undo = 'undo';
    case MoveTask = 'move_task';
    case ChangeStatus = 'change_status';
    case ChangeProject = 'change_project';
    case EditDate = 'edit_date';
}
