<?php

namespace App\Logging;

use App\Services\Redaction\RedactingLogProcessor;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\Logger;

/**
 * Log channel `tap`: adds the redacting processor to the channel's Monolog logger.
 */
final class RedactLogs
{
    public function __invoke(IlluminateLogger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Logger) {
            $monolog->pushProcessor(new RedactingLogProcessor);
        }
    }
}
