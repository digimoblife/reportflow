<?php

use App\Enums\TaskStatus;
use App\Enums\WaitingReason;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\ReminderRule;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = actingAsUser();
});

it('rejects a duplicate idempotency key (PRD §23)', function () {
    InboundMessage::factory()->create(['idempotency_key' => 'telegram:1:1', 'telegram_chat_id' => 1, 'telegram_message_id' => 1]);

    expect(inSavepoint(fn () => InboundMessage::factory()->create(['idempotency_key' => 'telegram:1:1', 'telegram_chat_id' => 1, 'telegram_message_id' => 1])))
        ->toThrow(QueryException::class);
});

it('requires telegram ids for telegram messages but not for dashboard messages', function () {
    InboundMessage::factory()->fromDashboard()->create();

    expect(inSavepoint(fn () => InboundMessage::factory()->create(['telegram_chat_id' => null])))
        ->toThrow(QueryException::class, 'inbound_messages_telegram_ids_required');
});

it('rejects a duplicate telegram_user_id', function () {
    User::factory()->create(['telegram_user_id' => 123456789]);

    expect(inSavepoint(fn () => User::factory()->create(['telegram_user_id' => 123456789])))->toThrow(QueryException::class);
});

it('allows users without a telegram account until M5', function () {
    $a = User::factory()->withoutTelegram()->create();
    $b = User::factory()->withoutTelegram()->create();

    expect($a->telegram_user_id)->toBeNull()->and($b->telegram_user_id)->toBeNull();
});

it('only allows waiting_reason while the task is waiting', function () {
    $task = Task::factory()->waiting(WaitingReason::Vendor)->create();
    expect($task->fresh()->waiting_reason)->toBe(WaitingReason::Vendor);

    Task::factory()->create(['status' => TaskStatus::Waiting, 'waiting_reason' => null]);

    expect(inSavepoint(fn () => Task::factory()->create(['status' => TaskStatus::InProgress, 'waiting_reason' => WaitingReason::Client])))
        ->toThrow(QueryException::class, 'tasks_waiting_reason_only_when_waiting');
});

it('rejects values outside the enum sets', function () {
    $project = Project::factory()->create();

    expect(inSavepoint(fn () => DB::table('tasks')->insert([
        'project_id' => $project->id, 'title' => 'x', 'status' => 'resolved', 'priority' => 'normal',
    ])))->toThrow(QueryException::class);

    expect(inSavepoint(fn () => DB::table('tasks')->insert([
        'project_id' => $project->id, 'title' => 'x', 'status' => 'open', 'priority' => 'urgent',
    ])))->toThrow(QueryException::class);
});

it('requires an explicit task status and defaults priority to normal', function () {
    $project = Project::factory()->create();

    expect(inSavepoint(fn () => DB::table('tasks')->insert(['project_id' => $project->id, 'title' => 'x'])))
        ->toThrow(QueryException::class);

    $id = DB::table('tasks')->insertGetId(['project_id' => $project->id, 'title' => 'x', 'status' => 'open']);
    $row = DB::table('tasks')->find($id);

    expect($row->priority)->toBe('normal')->and($row->version)->toBe(1);
});

it('moves activities along when a task changes project (composite FK, ON UPDATE CASCADE)', function () {
    $from = Project::factory()->create();
    $to = Project::factory()->create();
    $task = Task::factory()->for($from)->create();
    $activities = Activity::factory()->count(2)->for($task)->create();

    expect($activities->pluck('project_id')->unique()->all())->toBe([$from->id]);

    $task->update(['project_id' => $to->id]);

    expect(Activity::whereKey($activities->modelKeys())->pluck('project_id')->unique()->values()->all())->toBe([$to->id]);
});

it('rejects an activity whose project does not match its task', function () {
    $task = Task::factory()->create();
    $other = Project::factory()->create();

    expect(inSavepoint(fn () => Activity::factory()->for($task)->create(['project_id' => $other->id])))
        ->toThrow(QueryException::class, 'activities_task_project_foreign');
});

it('restricts deleting a project that still has tasks', function () {
    $task = Task::factory()->create();

    expect(inSavepoint(fn () => DB::table('projects')->where('id', $task->project_id)->delete()))->toThrow(QueryException::class);
});

it('rejects a second active report for the same project, type, period and language', function () {
    $report = Report::factory()->create();
    $attributes = $report->only(['project_id', 'type', 'period_start', 'period_end', 'language']);

    Report::factory()->create([...$attributes, 'language' => 'id']);

    expect(inSavepoint(fn () => Report::factory()->create($attributes)))->toThrow(QueryException::class, 'reports_active_period_unique');

    $report->update(['status' => 'cancelled']);
    Report::factory()->create($attributes);

    expect(Report::where('project_id', $report->project_id)->count())->toBe(3);
});

it('rejects a report period that ends before it starts', function () {
    expect(inSavepoint(fn () => Report::factory()->create(['period_start' => '2026-09-30', 'period_end' => '2026-09-01'])))
        ->toThrow(QueryException::class, 'reports_period_order');
});

it('allows only one global rule per user and reminder type', function () {
    ReminderRule::factory()->create();
    ReminderRule::factory()->create(['project_id' => Project::factory()]);

    expect(inSavepoint(fn () => ReminderRule::factory()->create()))->toThrow(QueryException::class, 'reminder_rules_user_project_type_unique');
});
