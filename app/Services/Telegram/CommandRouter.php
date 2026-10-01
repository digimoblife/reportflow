<?php

namespace App\Services\Telegram;

use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\User;
use App\Services\Redaction\RedactionService;
use App\Services\Reminder\ReminderSettings;
use App\Services\Worklog\Outcome;
use App\Services\Worklog\ProjectService;
use App\Services\Worklog\UndoService;
use Illuminate\Support\Facades\Lang;

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
        private readonly ListingCommands $listing,
        private readonly ReminderSettings $reminders,
        private readonly ReportCommands $reports,
        private readonly ProjectService $projects,
    ) {}

    /**
     * Must run inside the user's UserContext (counts the user's projects).
     */
    public function handle(string $commandName, User $user, int $chatId, Language $language, #[\SensitiveParameter] ?string $argument = null): void
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
            'projects' => $this->show($chatId, $this->listing->projects($language)),
            'project' => $this->project($user, $chatId, $language, $argument),
            'tasks' => $this->show($chatId, $this->listing->tasks(0, $language, $user->timezone)),
            'task' => $this->show($chatId, $this->listing->task($argument, $language, $user->timezone)),
            'inbox' => $this->show($chatId, $this->listing->inbox($language, $user->timezone)),
            'reminder' => $this->reminder($user, $chatId, $language, $argument),
            'report' => $this->reports->start($user, $chatId, $language, $argument),
            'review' => $this->reports->review($user, $chatId, $language),
            'reports' => $this->reports->reports($user, $chatId, $language),
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

    /**
     * @param  array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}  $view
     */
    private function show(int $chatId, array $view): void
    {
        $this->messenger->trySend($chatId, $view['text'], null, $view['keyboard'] === [] ? null : $view['keyboard']);
    }

    /**
     * `/project` (list), `/project <name or number>` (detail) and `/project baru <name>` (create). The name is redacted
     * before it is stored, exactly like a first-project name sent during onboarding.
     */
    private function project(User $user, int $chatId, Language $language, #[\SensitiveParameter] ?string $argument): void
    {
        $text = trim((string) $argument);

        if (preg_match('/^(?:baru|new)(?:\s+(.*))?$/isu', $text, $m) === 1) {
            $this->createProject($user, $chatId, $language, trim($m[1] ?? ''));

            return;
        }

        $this->show($chatId, $this->listing->project($argument, $language, $user->timezone));
    }

    private function createProject(User $user, int $chatId, Language $language, #[\SensitiveParameter] string $name): void
    {
        if ($name === '') {
            $this->reply($chatId, 'onboarding.new_project_usage', $language);

            return;
        }

        $redaction = RedactionService::forUser($user->id)->redact($name);

        if ($redaction->failed()) {
            $this->reply($chatId, 'security.redaction_error', $language);

            return;
        }

        if ($redaction->hasFindings()) {
            if ($redaction->secretCount() > 0) {
                $this->reply($chatId, 'security.credential_detected', $language, ['count' => $redaction->secretCount()]);
            }

            return;
        }

        $clean = $this->projects->normalizeName($redaction->text);

        if ($clean === null) {
            $this->reply($chatId, 'onboarding.name_invalid', $language);

            return;
        }

        [$project, $created] = $this->projects->findOrCreate($clean);

        $this->reply($chatId, $created ? 'onboarding.project_created' : 'onboarding.project_exists', $language, ['project' => $project->name]);
    }

    /**
     * `/reminder`, `/reminder on|off|status`, `/reminder daily HH:MM`, `/reminder monthly` (PRD §33). The argument is only
     * parsed here, never stored: a time is validated before it reaches ReminderSettings.
     */
    private function reminder(User $user, int $chatId, Language $language, #[\SensitiveParameter] ?string $argument): void
    {
        $words = preg_split('/\s+/', mb_strtolower(trim((string) $argument)), 2, PREG_SPLIT_NO_EMPTY) ?: [];
        $sub = $words[0] ?? 'status';
        $rest = $words[1] ?? '';

        match (true) {
            $sub === 'on' => $this->remindersOn($user, $chatId, $language),
            $sub === 'off' => $this->remindersOff($user, $chatId, $language),
            $sub === 'status' => $this->reminderStatus($user, $chatId, $language),
            $sub === 'monthly' => $this->monthlyReminder($user, $chatId, $language, $rest),
            $sub === 'daily' && $rest === '' => $this->reminderStatus($user, $chatId, $language),
            $sub === 'daily' => $this->reminders->setTime($rest)
                ? $this->reply($chatId, 'reminder.daily_set', $language, ['time' => $this->reminders->time()])
                : $this->reply($chatId, 'reminder.daily_invalid', $language),
            default => $this->reply($chatId, 'reminder.usage', $language),
        };
    }

    /** `/reminder monthly`, `monthly on|off`, `monthly HH:MM`. */
    private function monthlyReminder(User $user, int $chatId, Language $language, string $rest): void
    {
        match (true) {
            $rest === '' => $this->reminderStatus($user, $chatId, $language),
            $rest === 'on' => $this->monthlySwitch($chatId, $language, true),
            $rest === 'off' => $this->monthlySwitch($chatId, $language, false),
            preg_match('/^(?:days?|hari)\s+(\d+)$/u', $rest, $m) === 1 => $this->monthlyDays($chatId, $language, (int) $m[1]),
            $this->reminders->setMonthlyTime($rest) => $this->reply($chatId, 'reminder.monthly_set', $language, ['time' => $this->reminders->monthlyTime(), 'when' => $this->monthlyWhen($language)]),
            default => $this->reply($chatId, 'reminder.daily_invalid', $language),
        };
    }

    private function monthlyDays(int $chatId, Language $language, int $days): void
    {
        if (! $this->reminders->setMonthlyDaysBefore($days)) {
            $this->reply($chatId, 'reminder.monthly_days_invalid', $language, ['max' => ReminderSettings::MAX_MONTHLY_DAYS_BEFORE]);

            return;
        }

        $this->reply($chatId, 'reminder.monthly_days_set', $language, ['when' => $this->monthlyWhen($language)]);
    }

    /** "the last day of the month" or "3 days before the end of the month", in the user's language. */
    private function monthlyWhen(Language $language): string
    {
        $days = $this->reminders->monthlyDaysBefore();

        return $days === 0
            ? (string) Lang::get('ui.reminder_monthly_when.last', [], $language->value)
            : trans_choice('ui.reminder_monthly_when.before', $days, ['days' => $days], $language->value);
    }

    private function monthlySwitch(int $chatId, Language $language, bool $on): void
    {
        $this->reminders->setMonthlyEnabled($on);
        $this->reply($chatId, $on ? 'reminder.monthly_on' : 'reminder.monthly_off', $language);
    }

    private function remindersOn(User $user, int $chatId, Language $language): void
    {
        $this->reminders->setEnabled($user, true);
        $this->reply($chatId, 'reminder.on_done', $language);
    }

    private function remindersOff(User $user, int $chatId, Language $language): void
    {
        $this->reminders->setEnabled($user, false);
        $this->reply($chatId, 'reminder.off_done', $language);
    }

    private function reminderStatus(User $user, int $chatId, Language $language): void
    {
        $lang = $language->value;
        $enabled = $user->reminders_enabled && $this->reminders->daily()->enabled;
        $days = collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
            ->filter(fn (string $d): bool => in_array($d, array_map('strval', (array) $user->workdays), true))
            ->map(fn (string $d): string => (string) Lang::get('ui.dashboard.settings.days.'.$d, [], $lang))
            ->implode(', ');

        $this->reply($chatId, 'reminder.status', $language, [
            'state' => (string) Lang::get('ui.reminder_states.'.($enabled ? 'on' : 'off'), [], $lang),
            'time' => $this->reminders->time(),
            'days' => $days,
            'mstate' => (string) Lang::get('ui.reminder_states.'.($enabled && $this->reminders->monthly()->enabled ? 'on' : 'off'), [], $lang),
            'mtime' => $this->reminders->monthlyTime(),
            'mwhen' => $this->monthlyWhen($language),
        ]);
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
