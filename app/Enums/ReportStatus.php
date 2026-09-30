<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Report status (PRD §49 reports, §43).
 */
enum ReportStatus: string
{
    use EnumValues;

    case Draft = 'draft';
    case Generating = 'generating';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Outdated = 'outdated';
    case Cancelled = 'cancelled';
}
