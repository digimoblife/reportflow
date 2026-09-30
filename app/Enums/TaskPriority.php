<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Task priority (decision M1, see docs/DECISIONS.md).
 */
enum TaskPriority: string
{
    use EnumValues;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
