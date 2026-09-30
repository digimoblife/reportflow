<?php

namespace App\Services\Ai\Eval;

use App\Enums\ProjectStatus;
use App\Enums\TaskPersonRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WaitingReason;
use App\Models\Activity;
use App\Models\Person;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Str;

/**
 * Loads a dataset snapshot into the database for the current UserContext. The eval runner wraps this
 * in a transaction that is always rolled back.
 */
class SnapshotLoader
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function load(array $snapshot): SnapshotMap
    {
        $projects = [];
        foreach ((array) ($snapshot['projects'] ?? []) as $p) {
            $projects[(string) $p['key']] = Project::query()->create([
                'name' => $p['name'],
                'slug' => Str::slug((string) $p['name']),
                'aliases' => $p['aliases'] ?? [],
                'status' => ProjectStatus::Active,
            ])->id;
        }

        $people = [];
        foreach ((array) ($snapshot['people'] ?? []) as $person) {
            $people[(string) $person['key']] = Person::query()->create(['name' => $person['name'], 'aliases' => []])->id;
        }

        $tasks = [];
        $statuses = [];
        foreach ((array) ($snapshot['tasks'] ?? []) as $t) {
            $status = TaskStatus::from((string) $t['status']);

            $task = Task::query()->create([
                'project_id' => $projects[$t['project']],
                'title' => $t['title'],
                'status' => $status,
                'waiting_reason' => $status === TaskStatus::Waiting ? WaitingReason::from((string) ($t['waiting_reason'] ?? 'client')) : null,
                'priority' => TaskPriority::Normal,
                'completed_at' => $t['completed_at'] ?? null,
                'last_activity_at' => $t['last_activity_at'] ?? null,
                'version' => 1,
            ]);

            foreach ((array) ($t['people'] ?? []) as $personKey) {
                $task->people()->attach($people[$personKey], ['role' => TaskPersonRole::Stakeholder]);
            }

            foreach ((array) ($t['activities'] ?? []) as $a) {
                Activity::query()->create([
                    'task_id' => $task->id,
                    'project_id' => $task->project_id,
                    'activity_type' => $a['type'],
                    'summary' => $a['summary'],
                    'content_structured' => [],
                    'activity_date' => $a['date'],
                    'date_precision' => 'day',
                    'source' => 'manual',
                ]);
            }

            $tasks[(string) $t['key']] = $task->id;
            $statuses[(string) $t['key']] = $status->value;
        }

        return new SnapshotMap($projects, $tasks, $statuses);
    }
}
