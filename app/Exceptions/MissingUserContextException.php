<?php

namespace App\Exceptions;

use RuntimeException;

class MissingUserContextException extends RuntimeException
{
    public function __construct(string $message = 'No user context is set; user-scoped data cannot be queried. Use UserContext::runAs() or runAsSystem().')
    {
        parent::__construct($message);
    }
}
