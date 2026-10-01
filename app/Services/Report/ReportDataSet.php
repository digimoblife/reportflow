<?php

namespace App\Services\Report;

use Carbon\CarbonImmutable;

/**
 * The frozen input of one report version (PRD §23, §44, §45): what the period contains as of `snapshotAt`. Built by
 * ReportDataSelector, rendered into facts by ReportFactsBuilder, and the only data the AI is shown. Plain values, no
 * models: a snapshot must not change under a running generation.
 */
final readonly class ReportDataSet
{
    /**
     * @param  list<array{id: int, task_id: int, date: string, type: string, summary: string, source: string}>  $activities  oldest first
     * @param  array<int, array{id: int, title: string, status: string, waiting_reason: string|null, started: string|null, completed: string|null, last_activity: string|null, people: list<string>}>  $tasks  keyed by task id
     * @param  list<int>  $completedTaskIds
     * @param  list<int>  $ongoingTaskIds
     * @param  list<int>  $waitingTaskIds
     * @param  list<int>  $crossMonthTaskIds
     * @param  list<int>  $incidentActivityIds
     */
    public function __construct(
        public int $projectId,
        public string $projectName,
        public string $periodStart,
        public string $periodEnd,
        public string $timezone,
        public CarbonImmutable $snapshotAt,
        public array $activities,
        public array $tasks,
        public array $completedTaskIds,
        public array $ongoingTaskIds,
        public array $waitingTaskIds,
        public array $crossMonthTaskIds,
        public array $incidentActivityIds,
    ) {}

    /**
     * @return list<int>
     */
    public function sourceActivityIds(): array
    {
        return array_map(fn (array $a): int => $a['id'], $this->activities);
    }

    /**
     * @return list<array{id: int, task_id: int, date: string, type: string, summary: string, source: string}>
     */
    public function activitiesOf(int $taskId): array
    {
        return array_values(array_filter($this->activities, fn (array $a): bool => $a['task_id'] === $taskId));
    }

    /**
     * @return array{activities: int, completed: int, ongoing: int, waiting: int, cross_month: int, incidents: int}
     */
    public function counts(): array
    {
        return [
            'activities' => count($this->activities),
            'completed' => count($this->completedTaskIds),
            'ongoing' => count($this->ongoingTaskIds),
            'waiting' => count($this->waitingTaskIds),
            'cross_month' => count($this->crossMonthTaskIds),
            'incidents' => count($this->incidentActivityIds),
        ];
    }

    /**
     * Task ids that appear anywhere in the data: the only ids a narrative may refer to.
     *
     * @return list<int>
     */
    public function taskIds(): array
    {
        return array_map('intval', array_keys($this->tasks));
    }

    /**
     * The same data plus extra activities (kept in date order, incidents recomputed). Used to show the model and the
     * report what an edit will look like before the activities are written.
     *
     * @param  list<array{id: int, task_id: int, date: string, type: string, summary: string, source: string}>  $extra
     */
    public function withActivities(array $extra): self
    {
        $all = [...$this->activities, ...$extra];
        usort($all, fn (array $x, array $y): int => [$x['date'], $x['id']] <=> [$y['date'], $y['id']]);

        $incidents = array_values(array_map(
            fn (array $a): int => $a['id'],
            array_filter($all, fn (array $a): bool => in_array($a['type'], ['blocker', 'resolution'], true)),
        ));

        return new self($this->projectId, $this->projectName, $this->periodStart, $this->periodEnd, $this->timezone, $this->snapshotAt, $all, $this->tasks, $this->completedTaskIds, $this->ongoingTaskIds, $this->waitingTaskIds, $this->crossMonthTaskIds, $incidents);
    }
}
