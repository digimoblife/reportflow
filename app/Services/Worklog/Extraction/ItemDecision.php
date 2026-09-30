<?php

namespace App\Services\Worklog\Extraction;

/**
 * What the backend decided about one proposed item (PRD §13, §54).
 * Accepted: safe to apply automatically (M4). NeedsConfirmation: ask the user (medium/low, older date, ...).
 * Rejected: not usable; never applied.
 */
enum ItemDecision: string
{
    case Accepted = 'accepted';
    case NeedsConfirmation = 'needs_confirmation';
    case Rejected = 'rejected';
}
