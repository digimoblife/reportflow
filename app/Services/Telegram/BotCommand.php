<?php

namespace App\Services\Telegram;

/**
 * One bot command. `available` is true only for commands whose behaviour exists in the current milestone.
 */
final readonly class BotCommand
{
    public function __construct(
        public string $name,
        public bool $available = false,
    ) {}

    public function label(): string
    {
        return '/'.$this->name;
    }
}
