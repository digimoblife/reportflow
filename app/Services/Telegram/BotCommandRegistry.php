<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use Illuminate\Support\Facades\Lang;

/**
 * Single source of truth for the bot's commands (PRD §18, §20): routing, the "not available yet"
 * reply and the setMyCommands payload all read from here. Order and names follow PRD §20;
 * `/update` was dropped on purpose (natural language is the primary interface).
 */
final class BotCommandRegistry
{
    /** @var array<string, BotCommand> */
    private array $commands;

    public function __construct()
    {
        $available = ['start', 'help'];

        $this->commands = [];
        foreach ([
            'start', 'help', 'projects', 'project', 'tasks', 'task', 'undo',
            'inbox', 'report', 'reports', 'review', 'generate', 'settings', 'reminder',
        ] as $name) {
            $this->commands[$name] = new BotCommand($name, in_array($name, $available, true));
        }
    }

    /**
     * @return list<BotCommand>
     */
    public function all(): array
    {
        return array_values($this->commands);
    }

    public function find(string $name): ?BotCommand
    {
        return $this->commands[strtolower($name)] ?? null;
    }

    /**
     * Payload for setMyCommands in the given language.
     *
     * @return list<array{command: string, description: string}>
     */
    public function payload(Language $language): array
    {
        return array_map(fn (BotCommand $c): array => [
            'command' => $c->name,
            'description' => (string) Lang::get('bot_commands.'.$c->name, [], $language->value, false),
        ], $this->all());
    }
}
