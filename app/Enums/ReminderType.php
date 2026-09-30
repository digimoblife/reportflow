<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Reminder types in the MVP scope (PRD §25, §34). Other types are Phase 3.
 */
enum ReminderType: string
{
    use EnumValues;

    case DailyWorklog = 'daily_worklog';
    case MonthlyReport = 'monthly_report';
}
