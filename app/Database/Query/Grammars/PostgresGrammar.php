<?php

namespace App\Database\Query\Grammars;

use Illuminate\Database\Query\Grammars\PostgresGrammar as BasePostgresGrammar;

/**
 * Laravel binds DateTimeInterface query values with 'Y-m-d H:i:s', which drops the offset.
 * A Carbon in Asia/Jakarta would then be compared against timestamptz columns as if it were
 * UTC, shifting the result by 7 hours. Binding with the offset keeps the instant intact.
 */
class PostgresGrammar extends BasePostgresGrammar
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }
}
