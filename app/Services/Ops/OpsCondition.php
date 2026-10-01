<?php

namespace App\Services\Ops;

/**
 * One thing the system watches: a key, whether it is a problem right now, and the numbers an alert may quote
 * (never user text).
 */
final readonly class OpsCondition
{
    public function __construct(
        public string $key,
        public bool $active,
        public string|int|float|null $value = null,
        public string|int|float|null $limit = null,
    ) {}
}
