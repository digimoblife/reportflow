<?php

namespace App\Exceptions;

use RuntimeException;

class CrossUserWriteException extends RuntimeException
{
    public static function for(string $model, int $recordUserId, int $contextUserId): self
    {
        return new self("Refusing to create [{$model}] for user [{$recordUserId}] while acting as user [{$contextUserId}].");
    }
}
