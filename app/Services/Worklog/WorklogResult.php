<?php

namespace App\Services\Worklog;

/**
 * What processing an inbound message produced. M2: a placeholder. M4 fills in project/task/activity.
 */
final readonly class WorklogResult
{
    public function __construct(
        public bool $placeholder = true,
    ) {}
}
