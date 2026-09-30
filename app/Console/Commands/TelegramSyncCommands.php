<?php

namespace App\Console\Commands;

use App\Enums\Language;
use App\Services\Telegram\BotCommandRegistry;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:sync-commands {--dry-run : Print the payloads and do not call Telegram}')]
#[Description('Push the bot command list (PRD §20, from BotCommandRegistry) to Telegram via setMyCommands')]
class TelegramSyncCommands extends Command
{
    public function handle(BotCommandRegistry $registry, TelegramClient $client): int
    {
        // Default list (Indonesian, as in PRD §20) plus an English list for English clients.
        $sets = [
            ['language_code' => null, 'commands' => $registry->payload(Language::Indonesian)],
            ['language_code' => 'en', 'commands' => $registry->payload(Language::English)],
        ];

        if ($this->option('dry-run')) {
            $this->line(json_encode(array_map(
                static fn (array $set): array => ['method' => 'setMyCommands'] + $set,
                $sets,
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->components->info('Dry run: nothing was sent to Telegram.');

            return self::SUCCESS;
        }

        try {
            foreach ($sets as $set) {
                $client->setMyCommands($set['commands'], $set['language_code']);
            }
        } catch (TelegramApiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Bot commands synced ('.count($sets).' language sets).');

        return self::SUCCESS;
    }
}
