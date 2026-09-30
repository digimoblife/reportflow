<?php

namespace App\Services\Telegram;

use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\User;
use App\Services\Worklog\Outcome;
use App\Services\Worklog\UndoService;

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
        private readonly UndoService $undo,
        private readonly ConfirmationComposer $composer,
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
            'undo' => $this->undoLast($user, $chatId, $language),
            default => null,
        };
    }

    /**
     * Undo the user's most recent processed message that still has applied items (PRD §21).
     */
    private function undoLast(User $user, int $chatId, Language $language): void
    {
        $message = InboundMessage::query()
            ->where('status', InboundMessageStatus::Processed)
            ->whereNotNull('outcome')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->first(fn (InboundMessage $m): bool => Outcome::fromArray($m->outcome)?->applied() !== []
                && Outcome::fromArray($m->outcome) !== null);

        if ($message === null || $this->undo->undo($message) === []) {
            $this->reply($chatId, 'undo.nothing', $language);

            return;
        }

        $message->refresh();
        $outcome = Outcome::fromArray($message->outcome);

        if ($outcome !== null && $outcome->confirmationMessageId !== null) {
            $view = $this->composer->confirmation($message, $outcome, $language, $user->timezone);

            try {
                $this->messenger->edit($chatId, $outcome->confirmationMessageId, $view['text'], $view['keyboard'] ?? []);
            } catch (TelegramApiException) {
                // The bubble is gone or unchanged; the reply below still tells the user.
            }
        }

        $this->reply($chatId, 'undo.done', $language);
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
