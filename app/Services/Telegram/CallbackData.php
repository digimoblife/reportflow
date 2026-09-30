<?php

namespace App\Services\Telegram;

use InvalidArgumentException;

/**
 * The payload of an inline button (Telegram allows 64 bytes): `a:{inbound_message_id}:{action}[:{item}[:{arg}]]`.
 * Parsing is strict; anything else is ignored. The data carries no authority: the router still checks
 * that the pressing user owns the message (CLAUDE.md rule 11).
 */
final readonly class CallbackData
{
    public const MAX_BYTES = 64;

    public function __construct(
        public int $inboundMessageId,
        public string $action,
        public ?int $item = null,
        public ?string $arg = null,
    ) {
        if ($inboundMessageId < 1 || preg_match('/^[a-z_]{1,12}$/', $action) !== 1
            || ($item !== null && ($item < 0 || $item > 99))
            || ($arg !== null && preg_match('/^[A-Za-z0-9_-]{1,20}$/', $arg) !== 1)) {
            throw new InvalidArgumentException('Invalid callback data parts.');
        }

        if (strlen($this->encode()) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Callback data exceeds 64 bytes.');
        }
    }

    public static function parse(string $raw): ?self
    {
        if (strlen($raw) > self::MAX_BYTES
            || preg_match('/^a:([0-9]{1,12}):([a-z_]{1,12})(?::([0-9]{1,2})?(?::([A-Za-z0-9_-]{1,20}))?)?$/D', $raw, $m) !== 1) {
            return null;
        }

        try {
            return new self((int) $m[1], $m[2], isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null, $m[4] ?? null);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function encode(): string
    {
        $out = "a:{$this->inboundMessageId}:{$this->action}";

        if ($this->item !== null || $this->arg !== null) {
            $out .= ':'.($this->item ?? '');
        }

        if ($this->arg !== null) {
            $out .= ':'.$this->arg;
        }

        return $out;
    }
}
