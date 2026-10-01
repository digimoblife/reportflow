<?php

namespace App\Services\Ops;

use App\Models\Activity;
use App\Models\AiInteraction;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Person;
use App\Models\Project;
use App\Models\ReminderRule;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\ReportTemplate;
use App\Models\ReportVersion;
use App\Models\Task;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Hard deletion of client data on request (PRD §57). The only place where history is really removed; everything else
 * soft-deletes or appends. Works per user, all in one transaction, children before parents; report files leave the disk
 * after the commit. Records what was done as counts only (never titles or text).
 */
class Purger
{
    public const TARGETS = ['activity', 'task', 'project', 'report', 'message', 'user'];

    public function __construct(private readonly UserContext $context) {}

    /**
     * @throws InvalidArgumentException when the target is unknown or the record is not this user's
     */
    public function plan(string $target, ?int $id, int $userId, bool $withActivities = false, bool $withMessages = false): PurgePlan
    {
        return $this->context->runAs($userId, fn (): PurgePlan => $this->build($target, $id, $userId, $withActivities, $withMessages));
    }

    public function execute(PurgePlan $plan, int $userId): void
    {
        $this->context->runAs($userId, function () use ($plan, $userId): void {
            DB::transaction(function () use ($plan, $userId): void {
                $touchedTasks = $this->tasksToRecalculate($plan);

                foreach (PurgePlan::TABLES as $table) {
                    foreach (array_chunk($plan->ids[$table] ?? [], 1000) as $chunk) {
                        DB::table($table)->whereIn('id', $chunk)->delete();
                    }
                }

                $this->recalculateLastActivity($touchedTasks, $userId);

                OpsEvents::record(OpsEvents::PURGE, ['target' => $plan->target] + $plan->counts());
            });

            $this->deleteFiles($plan->files);
        });
    }

    private function build(string $target, ?int $id, int $userId, bool $withActivities, bool $withMessages): PurgePlan
    {
        if (! in_array($target, self::TARGETS, true)) {
            throw new InvalidArgumentException('Unknown purge target.');
        }

        if ($target !== 'user' && $id === null) {
            throw new InvalidArgumentException('An id is required.');
        }

        $a = $t = $p = $r = $m = [];
        $everything = false;

        switch ($target) {
            case 'activity':
                $a = $this->exists(Activity::query()->withTrashed(), $id);
                break;
            case 'task':
                $t = $this->exists(Task::query()->withTrashed(), $id);
                break;
            case 'project':
                $p = $this->exists(Project::query(), $id);
                break;
            case 'report':
                $r = $this->exists(Report::query(), $id);
                break;
            case 'message':
                $m = $this->exists(InboundMessage::query(), $id);
                break;
            case 'user':
                if ($id !== null && $id !== $userId) {
                    throw new InvalidArgumentException('Not this user.');
                }
                $everything = true;
                break;
        }

        if ($everything) {
            $p = $this->pluck(Project::query());
            $m = $this->pluck(InboundMessage::query());
        }

        // Projects bring their tasks, activities and reports; tasks bring their activities.
        $t = $this->merge($t, $p === [] ? [] : $this->pluck(Task::query()->withTrashed()->whereIn('project_id', $p)));
        $a = $this->merge($a, $p === [] ? [] : $this->pluck(Activity::query()->withTrashed()->whereIn('project_id', $p)));
        $a = $this->merge($a, $t === [] ? [] : $this->pluck(Activity::query()->withTrashed()->whereIn('task_id', $t)));
        $r = $this->merge($r, $p === [] ? [] : $this->pluck(Report::query()->whereIn('project_id', $p)));

        if ($withActivities && $m !== []) {
            $a = $this->merge($a, $this->pluck(Activity::query()->withTrashed()->whereIn('inbound_message_id', $m)));
        }

        $events = $t === [] ? [] : $this->ints(DB::table('task_events')->whereIn('task_id', $t)->pluck('id'));

        if ($withMessages) {
            $fromActivities = $a === [] ? [] : $this->column(Activity::query()->withTrashed()->whereIn('id', $a), 'inbound_message_id');
            $fromEvents = $events === [] ? [] : $this->rawColumn('task_events', $events, 'inbound_message_id');
            $m = $this->merge($m, $this->merge($fromActivities, $fromEvents));
        }

        $versions = $r === [] ? [] : $this->pluck(ReportVersion::query()->whereIn('report_id', $r));
        $files = $versions === [] ? collect() : ReportFile::query()->whereIn('report_version_id', $versions)->get(['id', 'file_path']);

        $rules = $p === [] ? [] : $this->pluckTable('reminder_rules', 'project_id', $p);
        $instances = $this->merge(
            $rules === [] ? [] : $this->pluckTable('reminder_instances', 'reminder_rule_id', $rules),
            [],
        );
        $templates = $p === [] ? [] : $this->pluckTable('report_templates', 'project_id', $p);

        $ai = $this->merge(
            $this->merge($m === [] ? [] : $this->pluckTable('ai_interactions', 'inbound_message_id', $m), $r === [] ? [] : $this->pluckTable('ai_interactions', 'report_id', $r)),
            $p === [] ? [] : $this->pluckTable('ai_interactions', 'project_id', $p),
        );
        $corrections = $m === [] ? [] : $this->pluckTable('corrections', 'inbound_message_id', $m);

        if ($everything) {
            $rules = $this->pluck(ReminderRule::query());
            $instances = $rules === [] ? [] : $this->pluckTable('reminder_instances', 'reminder_rule_id', $rules);
            $templates = $this->pluck(ReportTemplate::query());
            $ai = $this->pluck(AiInteraction::query());
            $corrections = $this->pluck(Correction::query());
        }

        $ids = [
            'report_files' => $this->ints($files->pluck('id')),
            'report_versions' => $versions,
            'reports' => $r,
            'ai_interactions' => $ai,
            'corrections' => $corrections,
            'reminder_instances' => $instances,
            'reminder_rules' => $rules,
            'task_people' => $t === [] ? [] : $this->pluckTable('task_people', 'task_id', $t),
            'task_events' => $events,
            'activities' => $a,
            'tasks' => $t,
            'report_templates' => $templates,
            'projects' => $p,
            'inbound_messages' => $m,
            'people' => $everything ? $this->pluck(Person::query()) : [],
        ];

        return new PurgePlan($target, $ids, array_values(array_map('strval', $files->pluck('file_path')->all())), $this->notes($target, $a, $m, $r, $withActivities, $withMessages));
    }

    /**
     * Things the purge leaves behind or cannot see; shown so the operator can decide (counts only).
     *
     * @param  list<int>  $a
     * @param  list<int>  $m
     * @param  list<int>  $r
     * @return array<string, int>
     */
    private function notes(string $target, array $a, array $m, array $r, bool $withActivities, bool $withMessages): array
    {
        $notes = [];

        if ($a !== [] && $target !== 'user' && $target !== 'project') {
            // Report text is derived from activities: a report built from a purged activity still contains its substance.
            $reports = 0;
            foreach (ReportVersion::query()->whereNotIn('report_id', $r)->get(['id', 'source_activity_ids']) as $version) {
                if (array_intersect($a, array_map('intval', (array) $version->source_activity_ids)) !== []) {
                    $reports++;
                }
            }
            if ($reports > 0) {
                $notes['report_versions_built_from_these_activities'] = $reports;
            }
        }

        if (! $withMessages && $a !== []) {
            $count = count(array_filter($this->column(Activity::query()->withTrashed()->whereIn('id', $a), 'inbound_message_id'), fn (int $id): bool => ! in_array($id, $m, true)));
            if ($count > 0) {
                $notes['source_messages_kept (use --with-messages)'] = $count;
            }
        }

        if (! $withActivities && $m !== []) {
            $count = Activity::query()->withTrashed()->whereIn('inbound_message_id', $m)->count();
            if ($count > 0 && $target === 'message') {
                $notes['activities_from_these_messages_kept (use --with-activities)'] = $count;
            }
        }

        return $notes;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return list<int>
     */
    private function exists($query, ?int $id): array
    {
        if ($id === null || ! $query->whereKey($id)->exists()) {
            throw new InvalidArgumentException('Record not found for this user.');
        }

        return [$id];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return list<int>
     */
    private function pluck($query): array
    {
        return $this->ints($query->pluck('id'));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return list<int>
     */
    private function column($query, string $column): array
    {
        return $this->ints($query->whereNotNull($column)->pluck($column));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function rawColumn(string $table, array $ids, string $column): array
    {
        return $this->ints(DB::table($table)->whereIn('id', $ids)->whereNotNull($column)->pluck($column));
    }

    /**
     * Ids of rows in a child table whose parent ids are already scoped to this user.
     *
     * @param  list<int>  $parents
     * @return list<int>
     */
    private function pluckTable(string $table, string $column, array $parents): array
    {
        return $this->ints(DB::table($table)->whereIn($column, $parents)->pluck('id'));
    }

    /**
     * @param  Collection<array-key, mixed>  $values
     * @return list<int>
     */
    private function ints($values): array
    {
        return array_values(array_unique(array_map('intval', $values->all())));
    }

    /**
     * @param  list<int>  $x
     * @param  list<int>  $y
     * @return list<int>
     */
    private function merge(array $x, array $y): array
    {
        return array_values(array_unique([...$x, ...$y]));
    }

    /**
     * Tasks that survive the purge but lose activities.
     *
     * @return list<int>
     */
    private function tasksToRecalculate(PurgePlan $plan): array
    {
        $activities = $plan->ids['activities'] ?? [];

        if ($activities === []) {
            return [];
        }

        $tasks = DB::table('activities')->whereIn('id', $activities)->pluck('task_id')->map(fn ($v): int => (int) $v)->unique()->all();

        return array_values(array_diff($tasks, $plan->ids['tasks'] ?? []));
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function recalculateLastActivity(array $taskIds, int $userId): void
    {
        if ($taskIds === []) {
            return;
        }

        $timezone = (string) (User::query()->whereKey($userId)->value('timezone') ?? 'UTC');

        foreach (Task::query()->withTrashed()->whereIn('id', $taskIds)->get() as $task) {
            $date = Activity::query()->where('task_id', $task->id)->max('activity_date');

            DB::table('tasks')->where('id', $task->id)->update([
                'last_activity_at' => $date === null ? null : CarbonImmutable::parse((string) $date, $timezone)->startOfDay()->utc(),
                'version' => DB::raw('version + 1'),
            ]);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteFiles(array $paths): void
    {
        $disk = Storage::disk('reports');

        foreach ($paths as $path) {
            try {
                $disk->delete($path);
            } catch (Throwable $e) {
                Log::warning('purge.file_not_deleted', ['exception' => $e::class]);
            }
        }
    }
}
