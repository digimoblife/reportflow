<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Metadata for tasks in the Waiting status (PRD §14).
 */
enum WaitingReason: string
{
    use EnumValues;

    case Client = 'client';
    case Vendor = 'vendor';
    case Api = 'api';
    case Launch = 'launch';
    case Confirmation = 'confirmation';
}
