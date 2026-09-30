<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Reminder priority (PRD §27).
 */
enum ReminderPriority: string
{
    use EnumValues;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Critical = 'critical';
}
