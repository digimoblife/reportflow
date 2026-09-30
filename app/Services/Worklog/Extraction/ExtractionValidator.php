<?php

namespace App\Services\Worklog\Extraction;

use App\Domain\Tasks\TaskStatusTransition;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Services\Worklog\CandidateSet;
use Carbon\CarbonImmutable;

/**
 * Backend validation of the model's proposal (PRD §54 steps 2–7; step 1 = schema, done by AIService
 * before this runs; step 8 = the database write, M4). The model proposes, the backend decides.
 *
 * Pure with respect to writes: it reads through user-scoped models only. Reason codes are machine
 * codes; no user text is copied into them.
 */
class ExtractionValidator
{
    /** Reasons that force a question to the user even when confidence is high. */
    private const CONFIRM_REASONS = ['date_older_than_30_days', 'project_missing', 'status_from_mismatch', 'status_transition_invalid', 'status_initial_invalid'];

    /** Statuses a brand-new task may start in (final choice belongs to M4). */
    private const INITIAL = [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Waiting, TaskStatus::Blocked, TaskStatus::Completed];

    public function __construct(private readonly ConfidencePolicy $policy) {}

    /**
     * @param  array<string, mixed>  $data  schema-valid model output
     */
    public function validate(array $data, CandidateSet $candidates, CarbonImmutable $today): ValidatedProposal
    {
        $items = [];

        foreach (array_values((array) ($data['items'] ?? [])) as $index => $item) {
            $items[] = $this->validateItem((int) $index, (array) $item, $candidates, $today);
        }

        $clarification = $data['clarification_needed'] ?? null;

        /** @var array{question: string, options?: list<string>}|null $clarification */
        $clarification = is_array($clarification) ? $clarification : null;

        return new ValidatedProposal($items, $clarification);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function validateItem(int $index, array $item, CandidateSet $candidates, CarbonImmutable $today): ValidatedItem
    {
        $reasons = [];
        $ref = (array) $item['task_ref'];
        $task = null;
        $taskId = null;

        $level = $this->policy->level((float) $item['confidence']);

        $reject = function (string $reason) use ($index, $item, &$reasons, $level): ValidatedItem {
            $reasons[] = $reason;

            return new ValidatedItem($index, ItemDecision::Rejected, $level, $reasons, $item, null, null, null, false, null);
        };

        // Steps 2–3: the task must come from the candidate list and belong to the user.
        if ($ref['type'] === 'existing') {
            if ($item['intent'] !== 'update_existing_task') {
                return $reject('intent_ref_mismatch');
            }

            $taskId = (int) $ref['task_id'];
            $task = $candidates->task($taskId);

            if ($task === null) {
                return $reject('task_ref_not_in_candidates');
            }

            // Belt and braces: re-read through the user-scoped model (rule 11).
            if (! Task::query()->whereKey($taskId)->exists()) {
                return $reject('task_not_owned');
            }
        } elseif ($item['intent'] !== 'new_task') {
            return $reject('intent_ref_mismatch');
        }

        // Step 4: project.
        $projectId = $item['project_id'] === null ? null : (int) $item['project_id'];

        if ($task !== null) {
            if ($projectId === null) {
                $projectId = $task['project_id'];
                $reasons[] = 'project_inferred_from_task';
            } elseif ($projectId !== $task['project_id']) {
                return $reject('project_task_mismatch');
            }
        } elseif ($projectId === null) {
            $reasons[] = 'project_missing';
        } elseif (! $candidates->hasProject($projectId)) {
            return $reject('project_unknown');
        }

        // Step 5: status transition matrix.
        $statusChange = null;
        $requested = $item['status_change'] ?? null;

        if (is_array($requested)) {
            $to = TaskStatus::from((string) $requested['to']);

            if ($task !== null) {
                $current = TaskStatus::from($task['status']);

                if ($requested['from'] !== null && $requested['from'] !== $current->value) {
                    $reasons[] = 'status_from_mismatch';
                }

                if ($to === $current) {
                    $reasons[] = 'status_unchanged';
                } elseif (! TaskStatusTransition::canTransition($current, $to)) {
                    $reasons[] = 'status_transition_invalid';
                } else {
                    $statusChange = ['from' => $current->value, 'to' => $to->value];
                }
            } elseif (in_array($to, self::INITIAL, true)) {
                $statusChange = ['from' => null, 'to' => $to->value];
            } else {
                $reasons[] = 'status_initial_invalid';
            }
        }

        $explicitTerminal = $statusChange !== null && in_array($statusChange['to'], [TaskStatus::Completed->value, TaskStatus::Cancelled->value], true);

        // Step 6: date.
        $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $item['activity']['date'], $today->getTimezone());

        if ($date === null || $date->format('Y-m-d') !== $item['activity']['date']) {
            return $reject('date_invalid');
        }

        if ($date->gt($today->startOfDay())) {
            return $reject('date_in_future');
        }

        if ($date->lt($today->startOfDay()->subDays((int) config('ai.extraction.backdate_confirm_days', 30)))) {
            $reasons[] = 'date_older_than_30_days';
        }

        // Step 7: confidence + deterministic signals.
        $contradictions = $task !== null ? $this->policy->contradictions($task, array_values(array_map('strval', (array) $item['people'])), $today) : [];
        array_push($reasons, ...$contradictions);

        $final = $this->policy->combine($level, [...$contradictions, ...array_values(array_intersect($reasons, self::CONFIRM_REASONS))]);

        $decision = $final === ConfidenceLevel::High ? ItemDecision::Accepted : ItemDecision::NeedsConfirmation;

        return new ValidatedItem($index, $decision, $final, array_values(array_unique($reasons)), $item, $taskId, $projectId, $statusChange, $explicitTerminal, $date);
    }
}
