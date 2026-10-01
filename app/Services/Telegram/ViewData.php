<?php

namespace App\Services\Telegram;

use InvalidArgumentException;

/**
 * Payload of a navigation button that is not tied to an inbound message (list paging, picking a task):
 * `v:{view}:{page}[:{ref}]`. Read-only views only; the data carries no authority (all queries are user-scoped).
 */
final readonly class ViewData
{
    public function __construct(
        public string $view,
        public int $page = 0,
        public ?int $ref = null,
    ) {
        if (preg_match('/^[a-z]{1,10}$/', $view) !== 1 || $page < 0 || $page > 99 || ($ref !== null && $ref < 1)) {
            throw new InvalidArgumentException('Invalid view data parts.');
        }
    }

    public static function parse(string $raw): ?self
    {
        if (strlen($raw) > CallbackData::MAX_BYTES || preg_match('/^v:([a-z]{1,10}):([0-9]{1,2})(?::([0-9]{1,12}))?$/D', $raw, $m) !== 1) {
            return null;
        }

        try {
            return new self($m[1], (int) $m[2], isset($m[3]) ? (int) $m[3] : null);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function encode(): string
    {
        return "v:{$this->view}:{$this->page}".($this->ref !== null ? ":{$this->ref}" : '');
    }
}
