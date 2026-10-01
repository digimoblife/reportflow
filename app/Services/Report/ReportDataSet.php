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
}
