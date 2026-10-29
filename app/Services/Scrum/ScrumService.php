<?php

namespace App\Services\Scrum;

use App\Enums\ActivitySource;
use App\Enums\ActivityType;
use App\Enums\DatePrecision;
use App\Enums\EventActor;
use App\Enums\TaskPersonRole;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Person;
use App\Models\Project;
use App\Models\ScrumEntry;
use App\Models\Task;
use App\Models\User;
use App\Services\Worklog\TaskLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class ScrumService
{
    public function __construct(
        private readonly TaskLifecycle $lifecycle,
    ) {}

    /**
     * @param  array{what_have_done?: string|null, issue?: string|null, what_to_do_next?: string|null}  $fields
     */
    public function saveEntry(User $user, int $projectId, int $personId, array $fields, ?string $scrumDate = null, ?int $inboundMessageId = null): ScrumEntry
    {
        $date = $scrumDate ?? CarbonImmutable::now($user->timezone)->format('Y-m-d');
        $project = Project::query()->findOrFail($projectId);
        $person = Person::query()->findOrFail($personId);

        $entry = ScrumEntry::query()->updateOrCreate([
            'user_id' => $user->id,
            'project_id' => $projectId,
            'person_id' => $personId,
            'scrum_date' => $date,
        ], [
            'what_have_done' => $fields['what_have_done'] ?? null,
            'issue' => $fields['issue'] ?? null,
            'what_to_do_next' => $fields['what_to_do_next'] ?? null,
        ]);

        $this->syncEntities($project, $person, $fields, $date, $inboundMessageId);

        return $entry;
    }

    /**
     * Parse items and create or link tasks and activities.
     *
     * @param  array{what_have_done?: string|null, issue?: string|null, what_to_do_next?: string|null}  $fields
     */
    private function syncEntities(Project $project, Person $person, array $fields, string $date, ?int $inboundMessageId): void
    {
        $when = CarbonImmutable::parse($date)->startOfDay();

        // 1. Process "What Have Done"
        $doneItems = $this->splitLines($fields['what_have_done'] ?? null);
        foreach ($doneItems as $summary) {
            $task = $this->findOrCreateTask($project, $summary, TaskStatus::InProgress, $inboundMessageId);
            $this->assignPerson($task, $person);

            Activity::query()->create([
                'task_id' => $task->id,
                'project_id' => $project->id,
                'inbound_message_id' => $inboundMessageId,
                'activity_type' => ActivityType::Development,
                'summary' => $summary,
                'content_structured' => [
                    'people' => [$person->name],
                    'scrum' => 'what_have_done',
                ],
                'activity_date' => $date,
                'date_precision' => DatePrecision::Day,
                'source' => ActivitySource::Telegram,
            ]);

            $this->lifecycle->touchActivity($task, $when);
        }

        // 2. Process "Issue" (Blockers)
        $issueItems = $this->splitLines($fields['issue'] ?? null);
        foreach ($issueItems as $issueText) {
            $task = $this->findOrCreateTask($project, 'Issue: '.$issueText, TaskStatus::Waiting, $inboundMessageId);
            $this->assignPerson($task, $person);

            Activity::query()->create([
                'task_id' => $task->id,
                'project_id' => $project->id,
                'inbound_message_id' => $inboundMessageId,
                'activity_type' => ActivityType::Blocker,
                'summary' => $issueText,
                'content_structured' => [
                    'people' => [$person->name],
                    'scrum' => 'issue',
                ],
                'activity_date' => $date,
                'date_precision' => DatePrecision::Day,
                'source' => ActivitySource::Telegram,
            ]);

            $this->lifecycle->touchActivity($task, $when);
        }

        // 3. Process "What To Do Next"
        $nextItems = $this->splitLines($fields['what_to_do_next'] ?? null);
        foreach ($nextItems as $nextTitle) {
            $task = $this->findOrCreateTask($project, $nextTitle, TaskStatus::InProgress, $inboundMessageId);
            $this->assignPerson($task, $person);
            $this->lifecycle->touchActivity($task, $when);
        }
    }

    private function findOrCreateTask(Project $project, string $text, TaskStatus $status, ?int $inboundMessageId): Task
    {
        $title = Str::limit(trim($text), 200, '');

        /** @var Task|null $existing */
        $existing = Task::query()
            ->where('project_id', $project->id)
            ->where('title', 'ILIKE', $title)
            ->whereIn('status', [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Waiting])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->lifecycle->create($project, $title, $status, EventActor::User, $inboundMessageId);
    }

    private function assignPerson(Task $task, Person $person): void
    {
        if (! $task->people()->whereKey($person->id)->exists()) {
            $task->people()->attach($person->id, ['role' => TaskPersonRole::Assignee]);
        }
    }

    /**
     * @return list<string>
     */
    private function splitLines(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($text));
        if ($lines === false) {
            return [];
        }

        $cleaned = [];
        foreach ($lines as $line) {
            $trimmed = trim(preg_replace('/^[-*•\d.]+\s*/u', '', trim($line)) ?? '');
            if ($trimmed !== '') {
                $cleaned[] = $trimmed;
            }
        }

        return $cleaned;
    }
}
