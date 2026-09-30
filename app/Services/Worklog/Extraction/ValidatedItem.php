<?php

namespace App\Services\Worklog\Extraction;

use Carbon\CarbonImmutable;

/**
 * One AI item after backend validation. Nothing here has been written to the database (M4 applies it).
 */
final readonly class ValidatedItem
{
    /**
     * @param  list<string>  $reasons  machine codes explaining downgrades and rejections (no user text)
     * @param  array{from: string|null, to: string}|null  $statusChange  after checking the transition matrix
     * @param  array<string, mixed>  $data  the item as returned by the model (schema-valid)
     */
    public function __construct(
        public int $index,
        public ItemDecision $decision,
        public ConfidenceLevel $level,
        public array $reasons,
        public array $data,
        public ?int $taskId,
        public ?int $projectId,
        public ?array $statusChange,
        public bool $explicitTerminal,
        public ?CarbonImmutable $activityDate,
    ) {}

    public function isNewTask(): bool
    {
        return $this->taskId === null;
    }

    public function has(string $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }
}
