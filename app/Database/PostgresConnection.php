<?php

namespace App\Database;

use App\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\PostgresConnection as BasePostgresConnection;

class PostgresConnection extends BasePostgresConnection
{
    protected function getDefaultQueryGrammar(): PostgresGrammar
    {
        return new PostgresGrammar($this);
    }
}
