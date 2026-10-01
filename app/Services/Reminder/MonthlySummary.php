<?php

namespace App\Services\Reminder;

use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use App\Services\Report\ReportDataSelector;
use Carbon\CarbonImmutable;

/**
 * What the monthly report reminder talks about (PRD §62): the active projects that have activity in the month, with the
 * counts a report would show. Same selector as the report itself, so the reminder never promises numbers the report
 * will not contain. Needs a UserContext.
 */
class MonthlySummary
{
    public function __construct(private readonly ReportDataSelector $selector) {}

    /**
     * @return array{0: string, 1: string} first and last day of the month that contains $date
     */
    public function range(string $date): array
    {
        $day = CarbonImmutable::parse($date);

        return [$day->startOfMonth()->format('Y-m-d'), $day->endOfMonth()->format('Y-m-d')];
    }

    /**
     * @return list<Project>
     */
    public function projects(string $from, string $to): array
    {
        $ids = array_map('intval', Activity::query()->whereBetween('activity_date', [$from, $to])->distinct()->pluck('project_id')->all());

        return array_values(Project::query()->where('status', 'active')->whereIn('id', $ids)->orderBy('name')->get()->all());
    }

    /**
     * @return list<array{project: Project, counts: array{activities: int, completed: int, ongoing: int, waiting: int, cross_month: int, incidents: int}}>
     */
    public function build(User $user, string $from, string $to): array
    {
        $out = [];

        foreach ($this->projects($from, $to) as $project) {
            $out[] = ['project' => $project, 'counts' => $this->selector->select($project, $from, $to, $user->timezone)->counts()];
        }

        return $out;
    }
}
