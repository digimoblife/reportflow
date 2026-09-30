<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Reminder instance state (PRD §29).
 */
enum ReminderState: string
{
    use EnumValues;

    case Scheduled = 'scheduled';
    case Sent = 'sent';
    case Acknowledged = 'acknowledged';
    case Snoozed = 'snoozed';
    case Completed = 'completed';
    case Dismissed = 'dismissed';
    case Cancelled = 'cancelled';
}
