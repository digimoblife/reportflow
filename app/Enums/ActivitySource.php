<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Where an activity came from (PRD §49; 'dashboard' added in M1, see docs/DECISIONS.md).
 */
enum ActivitySource: string
{
    use EnumValues;

    case Telegram = 'telegram';
    case Dashboard = 'dashboard';
    case ReportEdit = 'report_edit';
    case Manual = 'manual';
}
