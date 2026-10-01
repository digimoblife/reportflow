<?php

namespace App\Services\Telegram;

use InvalidArgumentException;

/**
 * Payload of a report button: `p:{report_id}:{version_no}:{action}[:{arg}]` (64 bytes at most). The version number is the
 * guard against stale buttons: a press made on an older review finds the report already moved on (PRD §23). Carries no
 * authority; handlers look the report up through the user-scoped model.
 */
final readonly class ReportCallback
{
    public const ACTIONS = ['ok', 're', 'ed', 'sec', 'cx', 'bk', 'nv', 'ig', 'wt', 'sk'];

    public function __construct(
        public int $reportId,
        public int $versionNo,
        public string $action,
        public ?int $arg = null,
    ) {
        if ($reportId < 1 || $versionNo < 0 || $versionNo > 999_999 || ! in_array($action, self::ACTIONS, true) || ($arg !== null && ($arg < 0 || $arg > 999))) {
            throw new InvalidArgumentException('Invalid report callback parts.');
        }
    }

    public static function parse(string $raw): ?self
    {
        if (strlen($raw) > CallbackData::MAX_BYTES || preg_match('/^p:([0-9]{1,12}):([0-9]{1,6}):([a-z]{2,3})(?::([0-9]{1,3}))?$/D', $raw, $m) !== 1) {
            return null;
        }

        try {
            return new self((int) $m[1], (int) $m[2], $m[3], isset($m[4]) ? (int) $m[4] : null);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function encode(): string
    {
        return "p:{$this->reportId}:{$this->versionNo}:{$this->action}".($this->arg !== null ? ":{$this->arg}" : '');
    }
}
