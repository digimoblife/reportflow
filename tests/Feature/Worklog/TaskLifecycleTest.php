<?php

use App\Domain\Tasks\InvalidTaskStatusTransition;
use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\Worklog\StaleTaskException;
use App\Services\Worklog\TaskLifecycle;
use App\Support\UserContext;
use Carbon\CarbonImmutable;

beforeEach(function () {
    actingAsUser();
    $this->project = Project::factory()->create();
    $this->lifecycle = app(TaskLifecycle::class);
});

it('creates a task with version 1 and a full-snapshot created event', function () {
    $task = $this->lifecycle->create($this->project, '  Fix login  ', TaskStatus::InProgress, EventActor::Ai, null);

    $event = TaskEvent::query()->sole();

    expect($task->title)->toBe('Fix login')->and($task->version)->toBe(1)
        ->and($task->started_at)->not->toBeNull()->and($task->completed_at)->toBeNull()
        ->and($event->event_type)->toBe(TaskEventType::Created)->and($event->actor)->toBe(EventActor::Ai)
        ->and($event->from_value)->toBeNull()
        ->and($event->to_value)->toEqual(['project_id' => $this->project->id, 'title' => 'Fix login', 'status' => 'in_progress', 'waiting_reason' => null]);
});

it('changes status through the matrix, bumps the version and records the snapshot', function () {
    $task = $this->lifecycle->create($this->project, 'T', TaskStatus::Open, EventActor::Ai);

    expect($this->lifecycle->changeStatus($task, TaskStatus::InProgress, EventActor::User))->toBeTrue();

    $event = TaskEvent::query()->where('event_type', TaskEventType::StatusChanged)->sole();
    expect($task->status)->toBe(TaskStatus::InProgress)->and($task->version)->toBe(2)->and($task->started_at)->not->toBeNull()
        ->and($event->from_value)->toEqual(['status' => 'open', 'waiting_reason' => null])
        ->and($event->to_value)->toEqual(['status' => 'in_progress', 'waiting_reason' => null])
        ->and($event->actor)->toBe(EventActor::User)
        ->and(Task::query()->find($task->id)->version)->toBe(2);
});

it('treats the same status as a no-op: no event, no version bump', function () {
    $task = $this->lifecycle->create($this->project, 'T', TaskStatus::Open, EventActor::Ai);

    expect($this->lifecycle->changeStatus($task, TaskStatus::Open, EventActor::Ai))->toBeFalse()
        ->and($task->version)->toBe(1)
        ->and(TaskEvent::query()->count())->toBe(1);
});

it('refuses transitions outside the matrix and changes nothing', function (TaskStatus $from, TaskStatus $to) {
    $task = $this->lifecycle->create($this->project, 'T', $from, EventActor::Ai);

    expect(fn () => $this->lifecycle->changeStatus($task, $to, EventActor::Ai))->toThrow(InvalidTaskStatusTransition::class);

    expect(Task::query()->find($task->id)->status)->toBe($from)->and(Task::query()->find($task->id)->version)->toBe(1)
        ->and(TaskEvent::query()->count())->toBe(1);
})->with([
    'completed -> waiting' => [TaskStatus::Completed, TaskStatus::Waiting],
    'completed -> cancelled' => [TaskStatus::Completed, TaskStatus::Cancelled],
    'cancelled -> in_progress' => [TaskStatus::Cancelled, TaskStatus::InProgress],
    'in_progress -> open' => [TaskStatus::InProgress, TaskStatus::Open],
]);

it('records completion time, clears it on reopen, and names reopen events', function () {
    $task = $this->lifecycle->create($this->project, 'T', TaskStatus::InProgress, EventActor::Ai);

    $this->lifecycle->changeStatus($task, TaskStatus::Completed, EventActor::Ai);
    expect($task->fresh()->completed_at)->not->toBeNull();

    $this->lifecycle->changeStatus($task, TaskStatus::InProgress, EventActor::Ai);
    $reopen = TaskEvent::query()->where('event_type', TaskEventType::Reopened)->sole();

    expect($task->fresh()->completed_at)->toBeNull()
        ->and($reopen->from_value['status'])->toBe('completed')->and($reopen->to_value['status'])->toBe('in_progress');

    $cancelled = $this->lifecycle->create($this->project, 'C', TaskStatus::Open, EventActor::Ai);
    $this->lifecycle->changeStatus($cancelled, TaskStatus::Cancelled, EventActor::Ai);
    $this->lifecycle->changeStatus($cancelled, TaskStatus::Open, EventActor::Ai);

    expect(TaskEvent::query()->where('event_type', TaskEventType::Reopened)->count())->toBe(2);
});

it('keeps started_at from the first time the task went in progress', function () {
    $task = $this->lifecycle->create($this->project, 'T', TaskStatus::Open, EventActor::Ai);
    $this->lifecycle->changeStatus($task, TaskStatus::InProgress, EventActor::Ai);
    $started = $task->fresh()->started_at;

    $this->lifecycle->changeStatus($task, TaskStatus::Waiting, EventActor::Ai);
    $this->lifecycle->changeStatus($task, TaskStatus::InProgress, EventActor::Ai);

    expect($task->fresh()->started_at->equalTo($started))->toBeTrue();
});

it('rejects a write based on a stale version (optimistic locking)', function () {
    $task = $this->lifecycle->create($this->project, 'T', TaskStatus::Open, EventActor::Ai);
    $copy = Task::query()->find($task->id);

    $this->lifecycle->changeStatus($task, TaskStatus::InProgress, EventActor::Ai);   // someone else got there first

    expect(fn () => $this->lifecycle->changeStatus($copy, TaskStatus::Blocked, EventActor::Ai, expectedVersion: 1))->toThrow(StaleTaskException::class);

    expect(Task::query()->find($task->id)->status)->toBe(TaskStatus::InProgress)
        ->and(TaskEvent::query()->count())->toBe(2);   // created + the one status change; the stale write left no event
});

it('keeps only the newest activity date and bumps the version each time', function () {
    $task = $this->lifecycle->create($this->project, 'T', TaskStatus::Open, EventActor::Ai);

    $this->lifecycle->touchActivity($task, CarbonImmutable::parse('2026-09-20', 'Asia/Jakarta'));
    $this->lifecycle->touchActivity($task, CarbonImmutable::parse('2026-09-10', 'Asia/Jakarta'));   // backdated: keeps the newer

    expect($task->fresh()->last_activity_at->format('Y-m-d H:i'))->toBe('2026-09-19 17:00')   // 20 Sept 00:00 Jakarta in UTC
        ->and($task->fresh()->version)->toBe(3);
});

it('is scoped to the acting user', function () {
    $task = $this->lifecycle->create($this->project, 'Mine', TaskStatus::Open, EventActor::Ai);
    $other = User::factory()->create();

    app(UserContext::class)->runAs($other->id, function () use ($task) {
        expect(Task::query()->find($task->id))->toBeNull()->and(TaskEvent::query()->count())->toBe(0);
    });
});
