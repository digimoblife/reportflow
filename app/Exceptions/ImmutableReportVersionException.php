<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An approved report version was about to be changed or deleted (PRD §43: approved versions are immutable).
 */
final class ImmutableReportVersionException extends RuntimeException
{
    public function __construct(int $versionId)
    {
        parent::__construct("Report version {$versionId} is approved and cannot be changed.");
    }
}
