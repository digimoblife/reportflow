<?php

namespace App\Services\Worklog;

use App\Services\Worklog\Extraction\ValidatedProposal;

/**
 * What processing an inbound message produced. M3: a validated proposal (nothing is written yet).
 * M4 applies accepted items to tasks/activities and asks about the rest.
 */
final readonly class WorklogResult
{
    public function __construct(
        public bool $placeholder = true,
        public ?ValidatedProposal $proposal = null,
    ) {}
}
