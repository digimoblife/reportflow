<?php

namespace App\Services\Report;

use App\Models\ReportVersion;

/**
 * Outcome of "edit via instruction" or "save as activity". Anything but OK means nothing was written.
 */
final readonly class InstructionResult
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    public const REDACTION_FAILED = 'redaction_failed';

    public const AI_FAILED = 'ai_failed';

    public const UNMATCHED = 'unmatched';

    public const NOTHING_TO_CHANGE = 'nothing_to_change';

    public const REWRITE_FAILED = 'rewrite_failed';

    /**
     * @param  list<string>  $unmatched  facts the instruction states that fit none of the report's tasks
     */
    public function __construct(
        public string $status,
        public ?ReportVersion $version = null,
        public int $factsSaved = 0,
        public array $unmatched = [],
    ) {}

    public function ok(): bool
    {
        return $this->status === self::OK;
    }
}
