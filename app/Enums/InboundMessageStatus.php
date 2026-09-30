<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Processing status of an inbound message (PRD §48).
 */
enum InboundMessageStatus: string
{
    use EnumValues;

    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case NeedsClarification = 'needs_clarification';
    case Failed = 'failed';
}
