<?php

namespace App\Services\Scrum;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ScrumEntry;
use App\Models\User;
use App\Services\Reminder\ReminderSchedule;
use App\Services\Telegram\TelegramMessenger;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * PRD Scrum §5: Morning Scrum Brief (Standup Helper).
 * Compiles the latest "What To Do Next" into "Rencana Hari Ini" and blockers/issues
 * for all team members in active projects, and delivers it to Telegram.
 */
class MorningScrumBriefService
{
    public const DEFAULT_BRIEF_TIME = '08:30';

    public function __construct(
        private readonly UserContext $context,
        private readonly TelegramMessenger $messenger,
    ) {}

    /**
     * Build the brief text for a specific project based on the latest available standup date on or before $date.
     */
    public function buildBrief(Project $project, CarbonImmutable $date, User $user): ?string
    {
        $latestDate = ScrumEntry::query()
            ->where('project_id', $project->id)
            ->where('scrum_date', '<=', $date->toDateString())
            ->max('scrum_date');

        if (! $latestDate) {
            return null;
        }

        $entries = ScrumEntry::query()
            ->where('project_id', $project->id)
            ->where('scrum_date', $latestDate)
            ->with('person')
            ->orderBy('person_id')
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        $lang = $user->default_language->value ?? 'id';
        $isId = $lang !== 'en';

        $localDate = $date->copy()->setTimezone($user->timezone);
        /** @var CarbonImmutable $localizedDate */
        $localizedDate = $localDate->locale($lang);
        $dateFormatted = $localizedDate->translatedFormat('l, j F Y');

        $title = "📋 Morning Scrum Brief — {$project->name}\n{$dateFormatted}";
        $bodyParts = [];

        foreach ($entries as $entry) {
            $personName = $entry->person !== null ? $entry->person->name : 'Team Member';
            $memberLines = ["👤 {$personName}"];
            $hasContent = false;

            if (! empty($entry->what_to_do_next)) {
                $planLabel = $isId ? '• Rencana Hari Ini:' : "• Today's Plan:";
                $lines = preg_split('/\r\n|\r|\n/', trim($entry->what_to_do_next)) ?: [];
                $formattedBullets = [];
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '') {
                        continue;
                    }
                    $cleaned = preg_replace('/^[-*•]\s*|^\d+\.\s*/u', '', $trimmed);
                    $formattedBullets[] = '  - '.ucfirst($cleaned ?? $trimmed);
                }

                if (! empty($formattedBullets)) {
                    $memberLines[] = $planLabel;
                    $memberLines = array_merge($memberLines, $formattedBullets);
                    $hasContent = true;
                }
            }

            if (! empty($entry->issue)) {
                $issueLabel = '• Issue:';
                $issueText = trim($entry->issue);
                if (str_contains($issueText, "\n")) {
                    $lines = preg_split('/\r\n|\r|\n/', $issueText) ?: [];
                    $issueBullets = [];
                    foreach ($lines as $line) {
                        $trimmed = trim($line);
                        if ($trimmed === '') {
                            continue;
                        }
                        $cleaned = preg_replace('/^[-*•]\s*|^\d+\.\s*/u', '', $trimmed);
                        $issueBullets[] = '  - '.ucfirst($cleaned ?? $trimmed);
                    }
                    if (! empty($issueBullets)) {
                        $memberLines[] = $issueLabel;
                        $memberLines = array_merge($memberLines, $issueBullets);
                        $hasContent = true;
                    }
                } else {
                    $cleaned = preg_replace('/^[-*•]\s*|^\d+\.\s*/u', '', $issueText);
                    $memberLines[] = "{$issueLabel} ".ucfirst($cleaned ?? $issueText);
                    $hasContent = true;
                }
            }

            if ($hasContent) {
                $bodyParts[] = implode("\n", $memberLines);
            }
        }

        if (empty($bodyParts)) {
            return null;
        }

        $footer = $isId
            ? '(Bahan pegangan obrolan Daily Standup pagi ini)'
            : "(Talking points for this morning's Daily Standup)";

        return $title."\n\n".implode("\n\n", $bodyParts)."\n\n".$footer;
    }

    /**
     * Send brief to a user's Telegram if due, or forcefully.
     *
     * @return int Number of project brief messages sent
     */
    public function sendBriefForUser(User $user, CarbonImmutable $now, bool $force = false): int
    {
        if ($user->telegram_user_id === null) {
            return 0;
        }

        $localNow = ReminderSchedule::localNow($user, $now);

        if (! $force) {
            if (! $user->reminders_enabled) {
                return 0;
            }

            if (! ReminderSchedule::isWorkday($user, $now)) {
                return 0;
            }

            if ($localNow->format('H:i') !== self::DEFAULT_BRIEF_TIME) {
                return 0;
            }

            $cacheKey = "scrum:brief-sent:{$user->id}:{$localNow->toDateString()}";
            if (Cache::has($cacheKey)) {
                return 0;
            }
        }

        $projects = Project::query()
            ->where('status', ProjectStatus::Active)
            ->orderBy('id')
            ->get();

        $sentCount = 0;

        foreach ($projects as $project) {
            $brief = $this->buildBrief($project, $localNow, $user);
            if ($brief !== null) {
                $this->messenger->send($user->telegram_user_id, $brief);
                $sentCount++;
            }
        }

        if ($sentCount > 0 && ! $force) {
            $cacheKey = "scrum:brief-sent:{$user->id}:{$localNow->toDateString()}";
            Cache::put($cacheKey, true, now()->addHours(20));
        }

        return $sentCount;
    }

    /**
     * Dispatch morning brief for all eligible users.
     */
    public function sendAllDue(?CarbonImmutable $now = null, bool $force = false): int
    {
        $now ??= CarbonImmutable::now('UTC');

        $users = $this->context->runAsSystem(fn () => User::query()
            ->whereNotNull('telegram_user_id')
            ->when(! $force, fn ($q) => $q->where('reminders_enabled', true))
            ->orderBy('id')
            ->get());

        $sent = 0;
        foreach ($users as $user) {
            $sent += $this->context->runAs($user->id, fn (): int => $this->sendBriefForUser($user, $now, $force));
        }

        return $sent;
    }
}
