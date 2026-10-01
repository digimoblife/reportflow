<?php

namespace App\Services\Telegram;

use InvalidArgumentException;

/**
 * "Make the report of this project for this month" button: `g:{project_id}:{YYYYMM}`. Carries no authority; the handler
 * looks the project up through the user-scoped model.
 */
final readonly class ReportStartCallback
{
    public function __construct(
        public int $projectId,
        public string $month,   // YYYY-MM
    ) {
        if ($projectId < 1 || preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException('Invalid report start callback parts.');
        }
    }

    public static function parse(string $raw): ?self
    {
        if (strlen($raw) > CallbackData::MAX_BYTES || preg_match('/^g:([0-9]{1,12}):(20[0-9]{2})(0[1-9]|1[0-2])$/D', $raw, $m) !== 1) {
            return null;
        }

        try {
            return new self((int) $m[1], $m[2].'-'.$m[3]);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function encode(): string
    {
        return "g:{$this->projectId}:".str_replace('-', '', $this->month);
    }
}
