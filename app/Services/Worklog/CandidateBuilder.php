<?php

namespace App\Services\Worklog;

use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Candidate retrieval (PRD §12): the backend, not the model, decides which tasks can be matched.
 *
 * - Project named in the message (name or alias, whole word, case-insensitive): every Open / In Progress /
 *   Waiting / Blocked task of that project, plus Completed tasks finished within the lookback window
 *   (possible reopen or late update), each with its last few activities.
 * - No project named: active tasks of all projects, in compact form.
 *
 * Everything goes through user-scoped models (CLAUDE.md rule 11); a UserContext is required.
 */
class CandidateBuilder
{
    private const ACTIVE = [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Waiting, TaskStatus::Blocked];

    public function build(string $message, CarbonImmutable $today): CandidateSet
    {
        $projects = [];
        $detected = [];

        foreach (Project::query()->where('status', ProjectStatus::Active)->orderBy('id')->get() as $project) {
            /** @var list<string> $aliases */
            $aliases = array_values(array_filter((array) $project->aliases, 'is_string'));
            $projects[$project->id] = ['id' => $project->id, 'name' => $project->name, 'aliases' => $aliases];

            if ($this->mentions($message, [$project->name, ...$aliases])) {
                $detected[] = $project->id;
            }
        }

        if ($projects === []) {
            return new CandidateSet([], [], []);
        }

        $lookback = (int) config('ai.extraction.completed_lookback_days', 30);

        $query = Task::query()
            ->with('people')
            ->whereIn('project_id', $detected === [] ? array_keys($projects) : $detected)
            ->where(function ($w) use ($today, $lookback): void {
                $w->whereIn('status', self::ACTIVE)
                    ->orWhere(fn ($c) => $c->where('status', TaskStatus::Completed)
                        ->where('completed_at', '>=', $today->subDays($lookback)->startOfDay()->utc()));
            })
            ->orderBy('project_id')
            ->orderByDesc('last_activity_at')
            ->orderBy('id');

        $tasks = $query->get();
        $activities = $this->recentActivities(array_values(array_map('intval', $tasks->modelKeys())));

        $candidates = [];
        foreach ($tasks as $task) {
            $candidates[$task->id] = [
                'id' => $task->id,
                'project_id' => $task->project_id,
                'title' => $task->title,
                'status' => $task->status->value,
                'completed_at' => $task->completed_at?->utc()->toIso8601String(),
                'last_activity_at' => $task->last_activity_at?->utc()->toIso8601String(),
                'people' => array_values(array_map('strval', $task->people->pluck('name')->all())),
                'recent_activities' => $activities[$task->id] ?? [],
            ];
        }

        return new CandidateSet($projects, $candidates, $detected);
    }

    /**
     * @param  list<string>  $names
     */
    private function mentions(string $message, array $names): bool
    {
        foreach ($names as $name) {
            $name = trim($name);

            if (mb_strlen($name) >= 2 && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Last N activities per task in one query. Task ids come from the user-scoped query above, so the
     * raw statement cannot reach another user's rows.
     *
     * @param  list<int>  $taskIds
     * @return array<int, list<array{date: string, type: string, summary: string}>>
     */
    private function recentActivities(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        $limit = max(1, (int) config('ai.extraction.recent_activities', 3));
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));

        $rows = DB::select(
            "select task_id, activity_date, activity_type, summary from (
                select task_id, activity_date, activity_type, summary,
                       row_number() over (partition by task_id order by activity_date desc, id desc) as rn
                from activities where task_id in ({$placeholders}) and deleted_at is null
            ) recent where rn <= {$limit} order by task_id, rn",
            $taskIds,
        );

        $byTask = [];
        foreach ($rows as $row) {
            $byTask[(int) $row->task_id][] = [
                'date' => (string) $row->activity_date,
                'type' => (string) $row->activity_type,
                'summary' => mb_substr((string) $row->summary, 0, 200),
            ];
        }

        return $byTask;
    }
}
