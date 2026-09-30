<?php

namespace App\Services\Redaction;

/**
 * Outcome of redaction: the clean text plus how many secrets were removed per category.
 */
final readonly class RedactionResult
{
    /**
     * @param  array<string, int>  $counts  category value => number of replacements
     */
    public function __construct(
        public string $text,
        public array $counts = [],
    ) {}

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function hasFindings(): bool
    {
        return $this->total() > 0;
    }

    /**
     * True when the text could not be checked and was replaced entirely (fail closed).
     * This is a processing error, not a "credential detected" finding.
     */
    public function failed(): bool
    {
        return ($this->counts[RedactionCategory::Error->value] ?? 0) > 0;
    }

    /**
     * Number of real secrets found (excludes the error marker).
     */
    public function secretCount(): int
    {
        return $this->total() - ($this->counts[RedactionCategory::Error->value] ?? 0);
    }
}
