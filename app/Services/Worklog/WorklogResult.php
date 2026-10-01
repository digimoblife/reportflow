<?php

namespace App\Services\Worklog;

use App\Services\Worklog\Extraction\ValidatedProposal;

/**
 * What processing an inbound message produced: the validated proposal and what was done with it (M4: accepted
 * items are written to tasks/activities, the rest waits for the user's answer).
 */
final readonly class WorklogResult
{
    public function __construct(
        public ValidatedProposal $proposal,
        public Outcome $outcome,
    ) {}
}
