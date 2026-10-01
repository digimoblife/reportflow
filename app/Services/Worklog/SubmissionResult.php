<?php

namespace App\Services\Worklog;

final readonly class SubmissionResult
{
    public const QUEUED = 'queued';

    public const DUPLICATE = 'duplicate';

    public const EMPTY = 'empty';

    public const INVALID = 'invalid';

    public const REDACTION_FAILED = 'redaction_failed';

    public function __construct(
        public string $status,
        public ?int $inboundMessageId = null,
        public int $secretCount = 0,
    ) {}

    public function accepted(): bool
    {
        return in_array($this->status, [self::QUEUED, self::DUPLICATE], true);
    }
}
