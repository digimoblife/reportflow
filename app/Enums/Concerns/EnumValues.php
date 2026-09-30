<?php

namespace App\Enums\Concerns;

/**
 * Helpers for string-backed enums.
 *
 * @phpstan-require-implements \BackedEnum
 */
trait EnumValues
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
