<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Project status (PRD §49 projects).
 */
enum ProjectStatus: string
{
    use EnumValues;

    case Active = 'active';
    case Archived = 'archived';
}
