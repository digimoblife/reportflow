<?php

namespace App\Services\Worklog;

use App\Enums\OutcomeState;

/**
 * The result of processing one inbound message (stored in inbound_messages.outcome).
 */
final readonly class Outcome
{
    /**
     * @param  list<OutcomeItem>  $items
     */
    public function __construct(
        public array $items,
        public bool $splitRequired = false,
        public ?int $confirmationMessageId = null,
        public bool $undone = false,
    ) {}

    public function hasPending(): bool
    {
        return $this->splitRequired || $this->count(OutcomeState::Pending) > 0;
    }

    public function count(OutcomeState $state): int
    {
        return count(array_filter($this->items, fn (OutcomeItem $i): bool => $i->state === $state));
    }

    /**
     * @return list<OutcomeItem>
     */
    public function applied(): array
    {
        return array_values(array_filter($this->items, fn (OutcomeItem $i): bool => $i->state === OutcomeState::Applied));
    }

    public function item(int $index): ?OutcomeItem
    {
        foreach ($this->items as $item) {
            if ($item->index === $index) {
                return $item;
            }
        }

        return null;
    }

    public function replaceItem(OutcomeItem $new): self
    {
        return new self(
            array_map(fn (OutcomeItem $i): OutcomeItem => $i->index === $new->index ? $new : $i, $this->items),
            $this->splitRequired,
            $this->confirmationMessageId,
            $this->undone,
        );
    }

    public function withConfirmationMessage(?int $id): self
    {
        return new self($this->items, $this->splitRequired, $id, $this->undone);
    }

    public function markUndone(): self
    {
        return new self($this->items, $this->splitRequired, $this->confirmationMessageId, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->hasPending() ? 'pending' : 'applied',
            'split_required' => $this->splitRequired,
            'confirmation_message_id' => $this->confirmationMessageId,
            'undone' => $this->undone,
            'items' => array_map(fn (OutcomeItem $i): array => $i->toArray(), $this->items),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        return new self(
            array_map(fn (array $i): OutcomeItem => OutcomeItem::fromArray($i), array_values((array) ($data['items'] ?? []))),
            (bool) ($data['split_required'] ?? false),
            isset($data['confirmation_message_id']) ? (int) $data['confirmation_message_id'] : null,
            (bool) ($data['undone'] ?? false),
        );
    }
}
