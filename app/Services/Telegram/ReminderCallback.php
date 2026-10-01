<?php

namespace App\Services\Telegram;

use InvalidArgumentException;

/**
 * Payload of a reminder button: `r:{reminder_instance_id}:{action}` (64 bytes at most). Carries no authority; the
 * handler looks the instance up through the user-scoped model.
 */
final readonly class ReminderCallback
{
    public const ACTIONS = ['add', 'none', 'later', 'tomorrow', 'gen', 'rev'];

    public function __construct(
        public int $instanceId,
        public string $action,
    ) {
        if ($instanceId < 1 || ! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid reminder callback parts.');
        }
    }

    public static function parse(string $raw): ?self
    {
        if (strlen($raw) > CallbackData::MAX_BYTES || preg_match('/^r:([0-9]{1,12}):([a-z]{1,10})$/D', $raw, $m) !== 1) {
            return null;
        }

        try {
            return new self((int) $m[1], $m[2]);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function encode(): string
    {
        return "r:{$this->instanceId}:{$this->action}";
    }
}
