<?php

namespace App\Services\Worklog;

use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;

/**
 * What an applied item looks like to a person: project, task, the note, the status movement and the date, as data.
 * Both channels render the same facts (Telegram as text lines, the dashboard as components); the labels come from
 * the lang files at render time, so this stays language-free.
 */
class OutcomeItemPresenter
{
    /**
     * @return array{project: string|null, task: string|null, is_new: bool, activity: array{type: string, summary: string, date: string}|null, status_from: string|null, status_to: string|null, reopened: bool, status_changed: bool, date_differs: bool}
     */
    public function present(OutcomeItem $item, string $timezone): array
    {
        $task = $item->taskId === null ? null : Task::query()->find($item->taskId);
        $project = $item->projectId === null ? null : Project::query()->find($item->projectId);
        $activity = $item->activityId === null ? null : Activity::query()->find($item->activityId);
        $date = $activity?->activity_date->format('Y-m-d');

        return [
            'project' => $project?->name,
            'task' => $task?->title,
            'is_new' => $item->createdTask,
            'activity' => $activity === null ? null : ['type' => $activity->activity_type->value, 'summary' => $activity->summary, 'date' => (string) $date],
            'status_from' => $item->statusTo !== null ? $item->statusFrom : null,
            'status_to' => $item->statusTo ?? $task?->status->value,
            'reopened' => $item->reopened,
            'status_changed' => $item->statusTo !== null,
            'date_differs' => $date !== null && $date !== CarbonImmutable::now($timezone)->format('Y-m-d'),
        ];
    }
}
