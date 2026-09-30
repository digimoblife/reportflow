<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Input channel of an inbound message or report version (PRD §23, §49).
 */
enum MessageSource: string
{
    use EnumValues;

    case Telegram = 'telegram';
    case Dashboard = 'dashboard';
}
