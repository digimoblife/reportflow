<?php

namespace App\Models\Concerns;

/**
 * Eloquent's default 'Y-m-d H:i:s' drops the UTC offset when writing, so a Carbon in
 * Asia/Jakarta would be stored as if it were UTC. Writing with the offset lets
 * PostgreSQL convert every timestamptz value to the correct instant.
 */
trait StoresTimestampsWithOffset
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }
}
