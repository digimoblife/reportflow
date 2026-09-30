<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Models\Project;
use App\Models\User;

/**
 * Answers bot commands. Only commands marked available in BotCommandRegistry do real work
 * (M2: /start, /help); the rest get a short "not available yet" reply, unknown ones an "unknown" reply.
 */
class CommandRouter
{
    public function __construct(
        private readonly BotCommandRegistry $registry,
        private readonly BotMessages $messages,
        private readonly TelegramMessenger $messenger,
        private readonly OnboardingState $onboarding,
    ) {}

    /**
     * Must run inside the user's UserContext (counts the user's projects).
     */
    public function handle(string $commandName, User $user, int $chatId, Language $language): void
    {
        $command = $this->registry->find($commandName);

        if ($command === null) {
            $this->reply($chatId, 'commands.unknown', $language, ['command' => '/'.$commandName]);

            return;
        }

        if (! $command->available) {
            $this->reply($chatId, 'commands.unavailable', $language, ['command' => $command->label()]);

            return;
        }

        match ($command->name) {
            'start' => $this->start($user, $chatId, $language),
            'help' => $this->reply($chatId, 'help.guide', $language),
            default => null,
        };
    }

    private function start(User $user, int $chatId, Language $language): void
    {
        $projects = Project::query()->count();

        if ($projects > 0) {
            $this->reply($chatId, 'onboarding.already_set_up', $language, ['count' => $projects]);

            return;
        }

        $this->onboarding->awaitProjectName($user->id);
        $this->reply($chatId, 'onboarding.welcome', $language);
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    private function reply(int $chatId, string $key, Language $language, array $replace = []): void
    {
        $this->messenger->trySend($chatId, $this->messages->get($key, $language, $replace));
    }
}
