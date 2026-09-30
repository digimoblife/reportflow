<?php

namespace App\Domain\Tasks;

use App\Enums\TaskStatus;
use DomainException;

class InvalidTaskStatusTransition extends DomainException
{
    public function __construct(
        public readonly TaskStatus $from,
        public readonly TaskStatus $to,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Task status transition from [{$from->value}] to [{$to->value}] is not allowed.");
    }
}
