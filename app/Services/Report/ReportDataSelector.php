<?php

namespace App\Services\Report;

use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * Picks what a report is made of (PRD §45): the activities whose date lies in the period, the tasks they belong to,
 * the tasks completed in the period, and the cross-month and incident groups. Periods are the user's calendar days
 * (converted to UTC only for the timestamp columns). Needs a UserContext; every query goes through scoped models.
 *
 * A re-generation passes `$frozenActivityIds`: the version's own list, so the data does not drift between versions
 * (PRD §23 "report selalu dibuat dari snapshot"). Task states are read live: they describe the situation at the end
 * of the period as known now.
 */
class ReportDataSelector
{
    private const ONGOING = [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Blocked];

    private const REPORTED = [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Blocked, TaskStatus::Waiting, TaskStatus::Completed];

    /**
     * @param  list<int>|null  $frozenActivityIds
     */
    public function select(Project $project, string $periodStart, string $periodEnd, string $timezone, ?CarbonImmutable $snapshotAt = null, ?array $frozenActivityIds = null): ReportDataSet
    {
        $snapshotAt ??= CarbonImmutable::now('UTC');

        $activityRows = $frozenActivityIds === null
            ? Activity::query()->where('project_id', $project->id)->whereBetween('activity_date', [$periodStart, $periodEnd])->get()
            : Activity::withTrashed()->where('project_id', $project->id)->whereIn('id', $frozenActivityIds)->get();

        $sorted = $activityRows->all();
        usort($sorted, fn (Activity $x, Activity $y): int => [$x->activity_date->format('Y-m-d'), $x->id] <=> [$y->activity_date->format('Y-m-d'), $y->id]);

        $activities = [];

        foreach ($sorted as $a) {
            $activities[] = [
                'id' => $a->id,
                'task_id' => $a->task_id,
                'date' => $a->activity_date->format('Y-m-d'),
                'type' => $a->activity_type->value,
                'summary' => $a->summary,
                'source' => $a->source->value,
            ];
        }

        $startUtc = CarbonImmutable::parse($periodStart, $timezone)->startOfDay()->utc();
        $endUtc = CarbonImmutable::parse($periodEnd, $timezone)->endOfDay()->utc();

        $completedInPeriod = array_values(array_map('intval', Task::query()->where('project_id', $project->id)->where('status', TaskStatus::Completed)
            ->whereBetween('completed_at', [$startUtc, $endUtc])->pluck('id')->all()));

        $taskIds = array_values(array_unique([...array_map(fn (array $a): int => $a['task_id'], $activities), ...$completedInPeriod]));
        $tasks = Task::query()->with('people')->whereIn('id', $taskIds)->get()->keyBy('id');

        $firstActivity = Activity::query()->selectRaw('task_id, min(activity_date) as first_date')->whereIn('task_id', $taskIds)->groupBy('task_id')
            ->pluck('first_date', 'task_id');
        $withActivityInPeriod = array_flip(array_map(fn (array $a): int => $a['task_id'], $activities));

        $rows = [];
        $completed = [];
        $ongoing = [];
        $waiting = [];
        $cross = [];

        foreach ($taskIds as $id) {
            /** @var Task|null $task */
            $task = $tasks->get($id);

            if ($task === null || ! in_array($task->status, self::REPORTED, true)) {
                continue;
            }

            $started = $this->earliest(
                $task->started_at?->copy()->setTimezone($timezone)->format('Y-m-d'),
                isset($firstActivity[$id]) ? substr((string) $firstActivity[$id], 0, 10) : null,
            );
            $completedOn = $task->completed_at?->copy()->setTimezone($timezone)->format('Y-m-d');

            $rows[$id] = [
                'id' => $id,
                'title' => $task->title,
                'status' => $task->status->value,
                'waiting_reason' => $task->waiting_reason?->value,
                'started' => $started,
                'completed' => $task->status === TaskStatus::Completed ? $completedOn : null,
                'last_activity' => $task->last_activity_at?->copy()->setTimezone($timezone)->format('Y-m-d'),
                'people' => $this->names($task),
            ];

            $inPeriod = isset($withActivityInPeriod[$id]);

            if ($task->status === TaskStatus::Completed && $completedOn !== null && $completedOn >= $periodStart && $completedOn <= $periodEnd) {
                $completed[] = $id;
            } elseif ($inPeriod && $task->status === TaskStatus::Waiting) {
                $waiting[] = $id;
            } elseif ($inPeriod && in_array($task->status, self::ONGOING, true)) {
                $ongoing[] = $id;
            }

            if ($inPeriod && $started !== null && $started < $periodStart) {
                $cross[] = $id;
            }
        }

        /** @param list<int> $ids */
        $byTitle = function (array $ids) use ($rows): array {
            usort($ids, fn (int $x, int $y): int => [mb_strtolower($rows[$x]['title']), $x] <=> [mb_strtolower($rows[$y]['title']), $y]);

            return $ids;
        };

        $incidents = array_values(array_map(
            fn (array $a): int => $a['id'],
            array_filter($activities, fn (array $a): bool => in_array($a['type'], [ActivityType::Blocker->value, ActivityType::Resolution->value], true)),
        ));

        return new ReportDataSet(
            projectId: $project->id,
            projectName: $project->name,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            timezone: $timezone,
            snapshotAt: $snapshotAt,
            activities: $activities,
            tasks: $rows,
            completedTaskIds: $byTitle($completed),
            ongoingTaskIds: $byTitle($ongoing),
            waitingTaskIds: $byTitle($waiting),
            crossMonthTaskIds: $byTitle($cross),
            incidentActivityIds: $incidents,
        );
    }

    /**
     * @return list<string>
     */
    private function names(Task $task): array
    {
        $names = [];

        foreach ($task->people as $person) {
            $names[] = (string) $person->name;
        }

        sort($names);

        return $names;
    }

    private function earliest(?string $a, ?string $b): ?string
    {
        return $a === null ? $b : ($b === null ? $a : min($a, $b));
    }
}
