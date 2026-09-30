<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * What became of one AI item after validation (stored in inbound_messages.outcome).
 * Applied: written to the database. Pending: waiting for the user's answer, nothing written.
 * Rejected: refused by the backend. Skipped: not attempted (e.g. more than 5 items in one message).
 */
enum OutcomeState: string
{
    use EnumValues;

    case Applied = 'applied';
    case Pending = 'pending';
    case Rejected = 'rejected';
    case Skipped = 'skipped';
}
