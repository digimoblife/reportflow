<?php

use App\Enums\CorrectionType;
use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Models\Activity;
use App\Models\Correction;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\Worklog\ActivityMover;
use App\Services\Worklog\StaleTaskException;
use App\Services\Worklog\TaskLifecycle;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 30, 10, 0, 0, 'Asia/Jakarta'));
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta']);
    $this->w = worklogWorld($this->user);
    $this->actingAs($this->user);
    app()->setLocale('id');
});

afterEach(fn () => Carbon::setTestNow());

describe('task list', function () {
    it('shows only the signed-in user\'s tasks', function () {
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $theirs = asUser($other->id, fn () => Task::factory()->for(Project::factory()->create(['user_id' => $other->id]))->create(['title' => 'Secret of somebody else']));

        Livewire::test(ListTasks::class)
            ->assertCanSeeTableRecords([$this->w['tracking'], $this->w['invoice']])
            ->assertCanNotSeeTableRecords([$theirs]);
    });

    it('filters by status, project and activity period', function () {
        Livewire::test(ListTasks::class)
            ->filterTable('status', ['waiting'])->assertCanSeeTableRecords([$this->w['sso']])->assertCanNotSeeTableRecords([$this->w['tracking'], $this->w['invoice']])
            ->removeTableFilter('status')
            ->filterTable('project_id', $this->w['kedai']->id)->assertCanSeeTableRecords([$this->w['menu']])->assertCanNotSeeTableRecords([$this->w['tracking']])
            ->removeTableFilter('project_id')
            ->filterTable('period', ['from' => '2026-09-27', 'until' => '2026-09-30'])
            ->assertCanSeeTableRecords([$this->w['invoice'], $this->w['menu']])->assertCanNotSeeTableRecords([$this->w['tracking'], $this->w['sso']]);
    });

    it('searches by title', function () {
        Livewire::test(ListTasks::class)->searchTable('Invoice')->assertCanSeeTableRecords([$this->w['invoice']])->assertCanNotSeeTableRecords([$this->w['tracking']]);
    });
});

describe('task detail', function () {
    it('shows the activities and the task history', function () {
        $task = $this->w['invoice'];
        Activity::factory()->for($task)->create(['summary' => 'Found the doubled loop', 'activity_date' => '2026-09-28']);
        app(TaskLifecycle::class)->changeStatus($task, TaskStatus::InProgress, EventActor::User);

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->assertSee('Invoice PDF Export Bug')->assertSee('Found the doubled loop')->assertSee('Status: Baru → Sedang dikerjakan');
    });

    it('cannot open another user\'s task', function () {
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $theirs = asUser($other->id, fn () => Task::factory()->for(Project::factory()->create(['user_id' => $other->id]))->create());

        $this->get('/admin/tasks/'.$theirs->id)->assertNotFound();
    });

    it('renames through the lifecycle (event + version)', function () {
        $task = $this->w['invoice'];
        $version = $task->version;

        Livewire::test(ViewTask::class, ['record' => $task->getKey()])
            ->callAction('rename', ['title' => 'Invoice PDF total fix'])->assertNotified();

        $fresh = $task->fresh();
        expect($fresh->title)->toBe('Invoice PDF total fix')->and($fresh->version)->toBe($version + 1)
            ->and(TaskEvent::query()->where('task_id', $task->id)->where('event_type', TaskEventType::TitleChanged)->count())->toBe(1);
    });

    it('changes status only along the matrix, and records the event', function () {
        $task = $this->w['invoice'];   // open

        $page = Livewire::test(ViewTask::class, ['record' => $task->getKey()]);
        $page->callAction('status', ['status' => 'in_progress'])->assertNotified();
        expect($task->fresh()->status)->toBe(TaskStatus::InProgress)
            ->and(TaskEvent::query()->where('task_id', $task->id)->where('event_type', TaskEventType::StatusChanged)->count())->toBe(1);

        // in_progress -> draft is not in the matrix, even if the request is forged.
        $page->callAction('status', ['status' => 'draft']);
        expect($task->fresh()->status)->toBe(TaskStatus::InProgress);
    });

    it('refuses an edit when the task changed elsewhere meanwhile, and says so', function () {
        $task = $this->w['invoice'];
        $page = Livewire::test(ViewTask::class, ['record' => $task->getKey()]);

        // Telegram moves the task while the page is open.
        app(TaskLifecycle::class)->changeStatus($task->fresh(), TaskStatus::InProgress, EventActor::User);

        $page->callAction('rename', ['title' => 'Should not be saved'])
            ->assertNotified(__('ui.dashboard.tasks.notices.stale'));

        expect($task->fresh()->title)->toBe('Invoice PDF Export Bug');

        // After reloading, the same edit goes through.
        $page->callAction('reload')->callAction('rename', ['title' => 'Saved after reload']);
        expect($task->fresh()->title)->toBe('Saved after reload');
    });
});

describe('moving activities', function () {
    beforeEach(function () {
        $this->a1 = Activity::factory()->for($this->w['invoice'])->create(['activity_date' => '2026-09-20', 'summary' => 'older']);
        $this->a2 = Activity::factory()->for($this->w['invoice'])->create(['activity_date' => '2026-09-29', 'summary' => 'newer']);
        $this->w['invoice']->update(['last_activity_at' => '2026-09-28 17:00:00+00']);
    });

    it('moves activities to another task, with events on both, new activity dates and a correction', function () {
        $from = $this->w['invoice'];
        $to = $this->w['tracking'];

        Livewire::test(ViewTask::class, ['record' => $from->getKey()])
            ->callAction('move', ['activities' => [$this->a2->id], 'target' => $to->id])->assertNotified();

        expect($this->a2->fresh()->task_id)->toBe($to->id)->and($this->a1->fresh()->task_id)->toBe($from->id)
            ->and($from->fresh()->last_activity_at->setTimezone('Asia/Jakarta')->format('Y-m-d'))->toBe('2026-09-20')
            ->and($to->fresh()->last_activity_at->setTimezone('Asia/Jakarta')->format('Y-m-d'))->toBe('2026-09-29')
            ->and(TaskEvent::query()->whereIn('task_id', [$from->id, $to->id])->where('event_type', TaskEventType::Moved)->count())->toBe(2)
            ->and(TaskEvent::query()->where('task_id', $to->id)->where('event_type', TaskEventType::Moved)->value('to_value'))->toMatchArray(['task_id' => $to->id, 'activity_ids' => [$this->a2->id]])
            ->and($from->fresh()->version)->toBe($from->version + 1)
            ->and($to->fresh()->version)->toBe($to->version + 1)
            ->and(Correction::query()->where('correction_type', CorrectionType::MoveTask)->count())->toBe(1);
    });

    it('follows the target task into its project (composite key stays consistent)', function () {
        app(ActivityMover::class)->move($this->w['invoice'], $this->w['menu'], [$this->a1->id], 'Asia/Jakarta');

        expect($this->a1->fresh()->project_id)->toBe($this->w['kedai']->id);
    });

    it('moves nothing for activities that belong to another task, or onto itself', function () {
        $foreign = Activity::factory()->for($this->w['tracking'])->create();

        expect(app(ActivityMover::class)->move($this->w['invoice'], $this->w['sso'], [$foreign->id], 'Asia/Jakarta'))->toBe(0)
            ->and(app(ActivityMover::class)->move($this->w['invoice'], $this->w['invoice'], [$this->a1->id], 'Asia/Jakarta'))->toBe(0)
            ->and($foreign->fresh()->task_id)->toBe($this->w['tracking']->id);
    });

    it('is refused when the target changed since the page loaded, and nothing moves', function () {
        $from = $this->w['invoice'];
        $page = Livewire::test(ViewTask::class, ['record' => $from->getKey()]);
        $target = $this->w['tracking']->fresh();

        // The version the form saw for the target is read when the action runs; make it stale by racing a writer in between.
        expect(fn () => app(ActivityMover::class)->move($from->fresh(), $target, [$this->a1->id], 'Asia/Jakarta', $from->version + 5, $target->version))
            ->toThrow(StaleTaskException::class);
        expect($this->a1->fresh()->task_id)->toBe($from->id);   // rolled back
    });
});
