<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Report types (PRD §49 reports). Only monthly/custom are built in the MVP; weekly/incident are Phase 3.
 */
enum ReportType: string
{
    use EnumValues;

    case Monthly = 'monthly';
    case Weekly = 'weekly';
    case Custom = 'custom';
    case Incident = 'incident';
}
