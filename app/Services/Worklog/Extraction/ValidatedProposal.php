<?php

namespace App\Services\Worklog\Extraction;

/**
 * The validated result of one extraction: what M4 may apply, what needs a question, what was refused.
 */
final readonly class ValidatedProposal
{
    /**
     * @param  list<ValidatedItem>  $items
     * @param  array{question: string, options?: list<string>}|null  $clarification
     */
    public function __construct(
        public array $items,
        public ?array $clarification = null,
    ) {}

    /**
     * @return list<ValidatedItem>
     */
    public function withDecision(ItemDecision $decision): array
    {
        return array_values(array_filter($this->items, fn (ValidatedItem $i): bool => $i->decision === $decision));
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Counts per decision, for logs (no content).
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $counts = [];
        foreach ($this->items as $item) {
            $counts[$item->decision->value] = ($counts[$item->decision->value] ?? 0) + 1;
        }

        return $counts;
    }
}
