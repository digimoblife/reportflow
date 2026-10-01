<?php

namespace App\Services\Worklog;

use App\Enums\CorrectionType;
use App\Enums\EventActor;
use App\Models\Activity;
use App\Models\Correction;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves activities from one task to another (PRD §22 Tasks page). History stays intact: the activities keep their
 * ids and dates, both tasks get a `moved` event, both versions are bumped and last_activity_at is recalculated, and
 * the move is recorded in `corrections`. Guarded by the versions the person was looking at (optimistic locking).
 */
class ActivityMover
{
    public function __construct(private readonly TaskLifecycle $lifecycle) {}

    /**
     * @param  list<int>  $activityIds
     * @return int how many activities moved
     *
     * @throws StaleTaskException when either task changed since the page was loaded
     */
    public function move(Task $source, Task $target, array $activityIds, string $timezone, ?int $sourceVersion = null, ?int $targetVersion = null): int
    {
        if ($source->id === $target->id || $activityIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($source, $target, $activityIds, $timezone, $sourceVersion, $targetVersion): int {
            // Lock in id order so two simultaneous moves cannot deadlock.
            $locked = Task::query()->whereIn('id', [$source->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $locked->get($source->id);
            $to = $locked->get($target->id);

            if ($from === null || $to === null) {
                return 0;
            }

            $ids = array_values(array_map('intval', Activity::query()->where('task_id', $from->id)->whereIn('id', $activityIds)->orderBy('id')->pluck('id')->all()));

            if ($ids === []) {
                return 0;
            }

            Activity::query()->whereIn('id', $ids)->update(['task_id' => $to->id, 'project_id' => $to->project_id]);

            $this->lifecycle->setLastActivity($from, $this->latest($from, $timezone), $sourceVersion);
            $this->lifecycle->setLastActivity($to, $this->latest($to, $timezone), $targetVersion);
            $this->lifecycle->recordActivitiesMoved($from, $to, $ids, EventActor::User);

            Correction::query()->create([
                'inbound_message_id' => null,
                'correction_type' => CorrectionType::MoveTask,
                'before' => ['task_id' => $from->id, 'activity_ids' => $ids],
                'after' => ['task_id' => $to->id, 'activity_ids' => $ids],
            ]);

            return count($ids);
        });
    }

    private function latest(Task $task, string $timezone): ?CarbonImmutable
    {
        $date = Activity::query()->where('task_id', $task->id)->max('activity_date');

        return $date === null ? null : CarbonImmutable::parse((string) $date, $timezone)->startOfDay();
    }
}
