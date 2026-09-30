<?php

use App\Domain\Tasks\TaskStatusTransition;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Support\UserContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;

function runAsLocal(Closure $callback): mixed
{
    $original = app()->environment();
    app()->instance('env', 'local');

    try {
        return $callback();
    } finally {
        app()->instance('env', $original);
    }
}

it('refuses to run outside the local environment', function () {
    expect(fn () => $this->seed(DemoSeeder::class))->toThrow(RuntimeException::class, 'APP_ENV=local');
    expect(User::count())->toBe(0);
});

it('seeds consistent fictional demo data in local', function () {
    runAsLocal(fn () => $this->seed(DatabaseSeeder::class));

    $owner = User::where('email', 'demo@example.test')->sole();
    actingAsUser($owner);

    expect(Project::pluck('name')->sort()->values()->all())->toBe(['Harbor Logistics Portal', 'Kedai Senja App'])
        ->and(Task::count())->toBe(7)
        ->and(collect(TaskStatus::cases())->every(fn (TaskStatus $s) => Task::where('status', $s)->exists()))->toBeTrue()
        ->and(Activity::join('tasks', 'tasks.id', '=', 'activities.task_id')
            ->whereColumn('activities.project_id', '!=', 'tasks.project_id')->count())->toBe(0);

    // Every seeded status change respects the PRD §14 matrix and ends at the task's current status.
    foreach (Task::with('events')->get() as $task) {
        $events = $task->events->sortBy('id')->values();
        expect($events->first()->event_type)->toBe(TaskEventType::Created);

        foreach ($events->skip(1) as $event) {
            expect(TaskStatusTransition::canTransition(
                TaskStatus::from($event->from_value['status']),
                TaskStatus::from($event->to_value['status']),
            ))->toBeTrue();
        }

        expect($events->last()->to_value['status'])->toBe($task->status->value);
    }

    // Cross-month data exists (PRD §16).
    expect(Activity::selectRaw("count(distinct to_char(activity_date, 'YYYY-MM')) as months")->value('months'))->toBeGreaterThanOrEqual(2);
});

it('is idempotent when run twice', function () {
    runAsLocal(fn () => $this->seed(DatabaseSeeder::class));
    runAsLocal(fn () => $this->seed(DatabaseSeeder::class));

    $counts = app(UserContext::class)->runAsSystem(fn () => [Project::count(), Task::count(), TaskEvent::count()]);

    expect(User::count())->toBe(1)->and($counts[0])->toBe(2)->and($counts[1])->toBe(7);
});
