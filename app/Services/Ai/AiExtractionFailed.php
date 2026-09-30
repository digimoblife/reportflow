<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The model did not return a usable extraction after the allowed retry (PRD §54 step 1).
 * The message holds validation codes only, never model output or user text.
 */
final class AiExtractionFailed extends RuntimeException
{
    /**
     * @param  list<string>  $codes
     */
    public function __construct(public readonly array $codes)
    {
        parent::__construct('AI extraction failed validation: '.implode(', ', array_slice($codes, 0, 10)));
    }
}
