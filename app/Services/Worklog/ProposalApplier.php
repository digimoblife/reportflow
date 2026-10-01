<?php

namespace App\Services\Worklog;

use App\Enums\ActivitySource;
use App\Enums\ActivityType;
use App\Enums\DatePrecision;
use App\Enums\EventActor;
use App\Enums\OutcomeState;
use App\Enums\TaskPersonRole;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Worklog\Extraction\ConfidenceLevel;
use App\Services\Worklog\Extraction\ConfidencePolicy;
use App\Services\Worklog\Extraction\ItemDecision;
use App\Services\Worklog\Extraction\ValidatedItem;
use App\Services\Worklog\Extraction\ValidatedProposal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies a validated AI proposal to the database (PRD §7, §10, §11, §14; CLAUDE.md rules 1, 4, 5).
 *
 * - One transaction per message: all accepted items are written together or not at all.
 * - Items that need the user's answer (unsure task match, missing project, old date) write NOTHING; they are
 *   kept as `pending` in the outcome and applied later through applyPending().
 * - Rejected items are recorded with their reason codes and never applied.
 * - More than MAX_ITEMS items: nothing is written and the user is asked to split the message (PRD §10).
 * - Every write goes through TaskLifecycle (transition matrix, versions, task_events).
 * - Idempotent: a message that already has an outcome is returned unchanged.
 */
class ProposalApplier
{
    public const MAX_ITEMS = 5;

    /** Activity types that count as real work: they move an Open task to In Progress (product rule, 2026-09-30). */
    public const WORK_TYPES = ['development', 'bug_fix', 'configuration', 'testing', 'deployment', 'investigation', 'research', 'documentation', 'milestone', 'resolution'];

    public function __construct(
        private readonly TaskLifecycle $lifecycle,
        private readonly ConfidencePolicy $policy,
    ) {}

    public function apply(InboundMessage $message, ValidatedProposal $proposal, CandidateSet $candidates): Outcome
    {
        $existing = Outcome::fromArray($message->outcome);

        if ($existing !== null) {
            return $existing;
        }

        if (count($proposal->items) > self::MAX_ITEMS) {
            $outcome = new Outcome(array_map(
                fn (ValidatedItem $i): OutcomeItem => new OutcomeItem($i->index, OutcomeState::Skipped, ['too_many_items']),
                $proposal->items,
            ), splitRequired: true);

            $this->save($message, $outcome);

            return $outcome;
        }

        return DB::transaction(function () use ($message, $proposal, $candidates): Outcome {
            $items = [];

            foreach ($proposal->items as $item) {
                $items[] = match ($item->decision) {
                    ItemDecision::Rejected => new OutcomeItem($item->index, OutcomeState::Rejected, $item->reasons, $item->projectId, $item->taskId, level: $item->level->value),
                    ItemDecision::NeedsConfirmation => $this->question($item, $candidates) ?? $this->applyItem($message, $item),
                    ItemDecision::Accepted => $this->applyItem($message, $item),
                };
            }

            $outcome = new Outcome($items);
            $this->save($message, $outcome);

            return $outcome;
        });
    }

    /**
     * Apply an item that was waiting for the user's answer.
     *
     * @param  int|null  $taskId  existing task chosen by the user (null = create a new task)
     * @param  int|null  $projectId  project chosen by the user (for a new task); defaults to the proposed one
     * @param  string|null  $date  Y-m-d chosen by the user; defaults to the proposed date
     */
    public function applyPending(InboundMessage $message, OutcomeItem $pending, ?int $taskId, ?int $projectId = null, ?string $date = null): OutcomeItem
    {
        return DB::transaction(function () use ($message, $pending, $taskId, $projectId, $date): OutcomeItem {
            $data = (array) $pending->pending;
            $when = CarbonImmutable::parse($date ?? $pending->pendingDate ?? (string) $data['activity']['date'], $this->timezone($message));

            $task = $taskId === null ? null : Task::query()->lockForUpdate()->findOrFail($taskId);
            $project = Project::query()->findOrFail($task->project_id ?? $projectId ?? $pending->projectId);

            $status = isset($data['status_change']['to']) && $task === null
                ? TaskStatus::tryFrom((string) $data['status_change']['to'])
                : null;

            $written = $this->write($message, $pending->index, $data, $task, $project, $status, $when);

            $outcome = Outcome::fromArray($message->outcome);

            if ($outcome !== null) {
                $this->save($message, $outcome->replaceItem($written));
            }

            return $written;
        });
    }

    /**
     * Which question (if any) an unsure item raises. Null = safe to apply.
     */
    private function question(ValidatedItem $item, CandidateSet $candidates): ?OutcomeItem
    {
        $question = match (true) {
            $item->has('project_missing') => 'project',
            $item->has('date_older_than_30_days') => 'date',
            // The model's own confidence, not the validator's level: that one is also lowered by status details, which
            // must not block a clear activity.
            ! $item->isNewTask() && $this->policy->level((float) $item->data['confidence']) !== ConfidenceLevel::High => 'match',
            ! $item->isNewTask() && array_intersect($item->reasons, ['task_cancelled', 'stale_task', 'person_mismatch']) !== [] => 'match',
            default => null,
        };

        if ($question === null) {
            return null; // e.g. only a status detail was questionable, or a low-confidence NEW task: safe and undoable
        }

        $options = [];

        if ($question === 'match') {
            $project = $item->projectId;
            $others = array_filter($candidates->tasks, fn (array $t): bool => $t['id'] !== $item->taskId && ($project === null || $t['project_id'] === $project));
            $options = array_slice(array_values(array_map(fn (array $t): int => $t['id'], $others)), 0, 3);
        }

        return new OutcomeItem(
            $item->index, OutcomeState::Pending, $item->reasons, $item->projectId, $item->taskId,
            question: $question, options: $options, pending: $item->data,
            pendingDate: $item->activityDate?->format('Y-m-d'), level: $item->level->value,
        );
    }

    private function applyItem(InboundMessage $message, ValidatedItem $item): OutcomeItem
    {
        $project = Project::query()->findOrFail($item->projectId);
        $task = $item->taskId === null ? null : Task::query()->lockForUpdate()->findOrFail($item->taskId);
        $status = $item->statusChange === null ? null : TaskStatus::from($item->statusChange['to']);

        return $this->write($message, $item->index, $item->data, $task, $project, $status, $item->activityDate ?? CarbonImmutable::now($this->timezone($message)), $item->reasons, $item->level->value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $reasons
     */
    private function write(InboundMessage $message, int $index, array $data, ?Task $task, Project $project, ?TaskStatus $requested, CarbonImmutable $when, array $reasons = [], ?string $level = null): OutcomeItem
    {
        $activity = (array) $data['activity'];
        $type = (string) $activity['type'];
        $isWork = in_array($type, self::WORK_TYPES, true);
        $created = $task === null;
        $statusFrom = $task?->status->value;
        $reopened = false;
        $restore = $task === null ? null : [
            'status' => $task->status->value,
            'waiting_reason' => $task->waiting_reason?->value,
            'started_at' => $task->started_at?->utc()->toIso8601String(),
            'completed_at' => $task->completed_at?->utc()->toIso8601String(),
            'last_activity_at' => $task->last_activity_at?->utc()->toIso8601String(),
        ];

        if ($task === null) {
            $initial = $requested ?? ($isWork ? TaskStatus::InProgress : TaskStatus::Open);
            $task = $this->lifecycle->create($project, (string) ($data['task_ref']['title'] ?? 'Task'), $initial, EventActor::Ai, $message->id);
            $statusTo = $initial->value;
        } else {
            $target = $requested ?? ($task->status === TaskStatus::Open && $isWork ? TaskStatus::InProgress : null);
            $statusTo = null;

            if ($target !== null && $this->lifecycle->changeStatus($task, $target, EventActor::Ai, $message->id)) {
                $statusTo = $target->value;
                $reopened = ($statusFrom === 'completed' && $target === TaskStatus::InProgress) || ($statusFrom === 'cancelled' && $target === TaskStatus::Open);
            }
        }

        $row = Activity::query()->create([
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'inbound_message_id' => $message->id,
            'activity_type' => ActivityType::from($type),
            'summary' => (string) $activity['summary'],
            'content_structured' => [
                'confidence' => $data['confidence'] ?? null,
                'matching_signals' => $data['matching_signals'] ?? [],
                'people' => $data['people'] ?? [],
                'missing_details' => $data['missing_details'] ?? [],
            ],
            'activity_date' => $when->format('Y-m-d'),
            'date_precision' => DatePrecision::tryFrom((string) ($activity['date_precision'] ?? 'day')) ?? DatePrecision::Day,
            'source' => ActivitySource::from($message->source->value),
        ]);

        $this->lifecycle->touchActivity($task, $when->startOfDay());
        $this->attachPeople($task, (array) ($data['people'] ?? []));

        return new OutcomeItem(
            $index, OutcomeState::Applied, $reasons, $task->project_id, $task->id, $created, $row->id,
            $created ? null : $statusFrom, $statusTo, $reopened, $task->version, pending: $data, pendingDate: $when->format('Y-m-d'), level: $level, restore: $restore,
        );
    }

    /**
     * Only people the user already has (name or alias, case-insensitive) are linked; nobody is invented from AI output.
     *
     * @param  array<int, mixed>  $names
     */
    private function attachPeople(Task $task, array $names): void
    {
        $wanted = array_filter(array_map(fn ($n): string => mb_strtolower(trim((string) $n)), $names));

        if ($wanted === []) {
            return;
        }

        foreach (Person::query()->get() as $person) {
            $known = array_map('mb_strtolower', [$person->name, ...array_map('strval', (array) $person->aliases)]);

            if (array_intersect($wanted, $known) !== [] && ! $task->people()->whereKey($person->id)->exists()) {
                $task->people()->attach($person->id, ['role' => TaskPersonRole::Stakeholder]);
            }
        }
    }

    private function save(InboundMessage $message, Outcome $outcome): void
    {
        InboundMessage::query()->whereKey($message->id)->update(['outcome' => $outcome->toArray()]);
        $message->outcome = $outcome->toArray();
    }

    private function timezone(InboundMessage $message): string
    {
        return (string) (User::query()->whereKey($message->user_id)->value('timezone') ?? 'Asia/Jakarta');
    }
}
