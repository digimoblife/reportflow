<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Precision of an activity date (PRD §49 activities).
 */
enum DatePrecision: string
{
    use EnumValues;

    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
}
