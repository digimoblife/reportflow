<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Supported languages for users, projects, templates and reports (PRD G7).
 */
enum Language: string
{
    use EnumValues;

    case Indonesian = 'id';
    case English = 'en';
}
