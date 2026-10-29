<?php

namespace App\Services\Telegram;

use InvalidArgumentException;

/**
 * Payload of an interactive Scrum button: `s:{action}[:{target}]` (max 64 bytes).
 * Actions:
 * - proj: select project by id
 * - mem: select member by id
 * - fld: select field (done, issue, next)
 * - save: save member scrum
 * - finish: finish session
 * - back: back to member selection
 */
final readonly class ScrumCallback
{
    public const ACTIONS = ['proj', 'mem', 'fld', 'save', 'finish', 'back'];

    public function __construct(
        public string $action,
        public ?string $target = null,
    ) {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid scrum callback action.');
        }
    }

    public static function parse(string $raw): ?self
    {
        if (strlen($raw) > CallbackData::MAX_BYTES || preg_match('/^s:([a-z]{3,6})(?::([A-Za-z0-9_-]{1,20}))?$/D', $raw, $m) !== 1) {
            return null;
        }

        try {
            return new self($m[1], $m[2] ?? null);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function encode(): string
    {
        return $this->target !== null ? "s:{$this->action}:{$this->target}" : "s:{$this->action}";
    }
}
