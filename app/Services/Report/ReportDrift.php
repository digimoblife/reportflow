<?php

namespace App\Services\Report;

use App\Models\Activity;
use App\Models\Report;
use App\Models\ReportVersion;
use App\Models\TaskEvent;
use Carbon\CarbonImmutable;

/**
 * Late entries (PRD §43): how much the data of a report's period changed after the report's snapshot. Counted against the
 * current version's `data_snapshot_at`, or against the moment the person chose "Abaikan", whichever is later:
 *
 * - new:     an activity dated inside the period was written after the baseline and is not among the version's sources;
 * - changed: a source (or period) activity was edited after the baseline;
 * - removed: a source activity was deleted after the baseline;
 * - tasks:   a task the report is about changed status, title or place after the baseline (task_events).
 *
 * Read-only; needs a UserContext. Activities that an instruction edit added to the version itself are not drift.
 */
class ReportDrift
{
    /**
     * @return array{new: int, changed: int, removed: int, tasks: int, total: int}
     */
    public function changes(Report $report): array
    {
        $version = $report->current_version_id === null ? null : ReportVersion::query()->find($report->current_version_id);

        if ($version === null) {
            return ['new' => 0, 'changed' => 0, 'removed' => 0, 'tasks' => 0, 'total' => 0];
        }

        $baseline = CarbonImmutable::instance($version->data_snapshot_at);

        if ($report->drift_dismissed_at !== null && $report->drift_dismissed_at->greaterThan($baseline)) {
            $baseline = CarbonImmutable::instance($report->drift_dismissed_at);
        }

        $from = $report->period_start->format('Y-m-d');
        $to = $report->period_end->format('Y-m-d');
        $sources = array_map('intval', $version->source_activity_ids);

        $inPeriod = fn () => Activity::query()->where('project_id', $report->project_id)->whereBetween('activity_date', [$from, $to]);

        $new = $inPeriod()->where('created_at', '>', $baseline)->whereNotIn('id', $sources)->count();

        $changed = $inPeriod()->where('created_at', '<=', $baseline)->where('updated_at', '>', $baseline)->count();

        $removed = Activity::onlyTrashed()->whereIn('id', $sources)->where('deleted_at', '>', $baseline)->count();

        $taskIds = array_values(array_unique(array_map('intval', Activity::withTrashed()->whereIn('id', $sources)->pluck('task_id')->all())));
        $tasks = $taskIds === [] ? 0 : TaskEvent::query()->whereIn('task_id', $taskIds)->where('created_at', '>', $baseline)->distinct()->count('task_id');

        return ['new' => $new, 'changed' => $changed, 'removed' => $removed, 'tasks' => $tasks, 'total' => $new + $changed + $removed + $tasks];
    }

    public function count(Report $report): int
    {
        return $this->changes($report)['total'];
    }
}
