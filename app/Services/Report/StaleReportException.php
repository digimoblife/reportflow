<?php

namespace App\Services\Report;

use RuntimeException;

/**
 * The report moved on (a new version appeared) since the person opened it (PRD §23 optimistic locking).
 */
final class StaleReportException extends RuntimeException
{
    public function __construct(public readonly int $reportId)
    {
        parent::__construct("Report {$reportId} changed since it was loaded.");
    }
}
