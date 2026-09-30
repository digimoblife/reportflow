<?php

use App\Enums\ActivityType;
use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\MessageSource;
use App\Enums\ProjectStatus;
use App\Enums\ReminderState;
use App\Enums\ReportFileFormat;
use App\Enums\ReportStatus;
use App\Enums\TaskEventType;
use App\Enums\TaskPersonRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\AiInteraction;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Person;
use App\Models\Project;
use App\Models\ReminderInstance;
use App\Models\ReminderRule;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\ReportTemplate;
use App\Models\ReportVersion;
use App\Models\Task;
use App\Models\TaskEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

beforeEach(function () {
    $this->user = actingAsUser();
});

it('casts user profile columns', function () {
    $user = $this->user->fresh();

    expect($user->default_language)->toBe(Language::Indonesian)
        ->and($user->timezone)->toBe('Asia/Jakarta')
        ->and($user->workdays)->toBe(['mon', 'tue', 'wed', 'thu', 'fri'])
        ->and($user->reminders_enabled)->toBeTrue()
        ->and($user->telegram_user_id)->toBeInt();
});

it('links projects, tasks, activities, people and events', function () {
    $project = Project::factory()->create(['aliases' => ['NC', 'nineclub']]);
    $message = InboundMessage::factory()->create();
    $task = Task::factory()->for($project)->waiting()->create();
    $activity = Activity::factory()->for($task)->create([
        'inbound_message_id' => $message->id,
        'activity_type' => ActivityType::Milestone,
        'content_structured' => ['entities' => ['domain']],
    ]);
    $person = Person::factory()->create();
    $task->people()->attach($person, ['role' => TaskPersonRole::Requester]);
    $event = TaskEvent::factory()->for($task)->create([
        'event_type' => TaskEventType::StatusChanged,
        'from_value' => ['status' => 'in_progress', 'waiting_reason' => null],
        'to_value' => ['status' => 'waiting', 'waiting_reason' => 'client'],
        'inbound_message_id' => $message->id,
    ]);

    $project = $project->fresh();
    $task = $task->fresh();
    $activity = $activity->fresh();

    expect($project->user->is($this->user))->toBeTrue()
        ->and($project->aliases)->toBe(['NC', 'nineclub'])
        ->and($project->status)->toBe(ProjectStatus::Active)
        ->and($project->tasks->modelKeys())->toBe([$task->id])
        ->and($project->activities->modelKeys())->toBe([$activity->id])
        ->and($task->status)->toBe(TaskStatus::Waiting)
        ->and($task->priority)->toBe(TaskPriority::Normal)
        ->and($task->started_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($task->activities->modelKeys())->toBe([$activity->id])
        ->and($task->events->first()->to_value)->toBe(['status' => 'waiting', 'waiting_reason' => 'client'])
        ->and($task->people->first()->assignment->role)->toBe(TaskPersonRole::Requester)
        ->and($person->tasks->modelKeys())->toBe([$task->id])
        ->and($activity->activity_type)->toBe(ActivityType::Milestone)
        ->and($activity->content_structured)->toBe(['entities' => ['domain']])
        ->and($activity->activity_date)->toBeInstanceOf(CarbonInterface::class)
        ->and($activity->inboundMessage->is($message))->toBeTrue()
        ->and($event->fresh()->task->is($task))->toBeTrue()
        ->and($message->fresh()->status)->toBe(InboundMessageStatus::Received)
        ->and($message->source)->toBe(MessageSource::Telegram)
        ->and($message->activities->modelKeys())->toBe([$activity->id])
        ->and($message->taskEvents->modelKeys())->toBe([$event->id]);
});

it('soft deletes tasks and activities while keeping the audit trail reachable', function () {
    $task = Task::factory()->create();
    $event = TaskEvent::factory()->for($task)->create();
    $activity = Activity::factory()->for($task)->create();

    $activity->delete();
    $task->delete();

    expect(Task::find($task->id))->toBeNull()
        ->and(Task::withTrashed()->find($task->id))->not->toBeNull()
        ->and(Activity::find($activity->id))->toBeNull()
        ->and($event->fresh()->task->is($task))->toBeTrue();
});

it('links reports, versions, files and templates', function () {
    $template = ReportTemplate::factory()->create();
    $project = Project::factory()->create(['report_template_id' => $template->id]);
    $report = Report::factory()->for($project)->create(['template_id' => $template->id]);
    $version = ReportVersion::factory()->for($report)->create(['source_activity_ids' => [1, 2, 3]]);
    $report->update(['current_version_id' => $version->id]);
    $file = ReportFile::factory()->for($version)->create(['format' => ReportFileFormat::Md]);

    $report = $report->fresh();

    expect($project->fresh()->reportTemplate->is($template))->toBeTrue()
        ->and($template->reports->modelKeys())->toBe([$report->id])
        ->and($report->status)->toBe(ReportStatus::Draft)
        ->and($report->template->is($template))->toBeTrue()
        ->and($report->currentVersion->is($version))->toBeTrue()
        ->and($report->versions->first()->source_activity_ids)->toBe([1, 2, 3])
        ->and($version->files->first()->format)->toBe(ReportFileFormat::Md)
        ->and($file->reportVersion->report->is($report))->toBeTrue();
});

it('links reminders, corrections and ai interactions', function () {
    $task = Task::factory()->create();
    $rule = ReminderRule::factory()->create();
    $instance = ReminderInstance::factory()->for($rule, 'rule')->create(['task_id' => $task->id]);
    $message = InboundMessage::factory()->create();
    $correction = Correction::factory()->create(['inbound_message_id' => $message->id]);
    $interaction = AiInteraction::factory()->create(['inbound_message_id' => $message->id, 'project_id' => $task->project_id]);

    expect($rule->instances->first()->status)->toBe(ReminderState::Scheduled)
        ->and($instance->rule->is($rule))->toBeTrue()
        ->and($instance->task->is($task))->toBeTrue()
        ->and($correction->fresh()->before)->toBe(['status' => 'completed', 'waiting_reason' => null])
        ->and($message->corrections->modelKeys())->toBe([$correction->id])
        ->and($interaction->fresh()->input)->toBeArray()
        ->and($interaction->success)->toBeTrue()
        ->and($message->aiInteractions->modelKeys())->toBe([$interaction->id])
        ->and($this->user->aiInteractions()->count())->toBe(1);
});

it('stores timestamps in UTC', function () {
    $message = InboundMessage::factory()->create(['received_at' => '2026-09-15 08:00:00+07:00']);

    expect($message->fresh()->received_at->utc()->toDateTimeString())->toBe('2026-09-15 01:00:00');
});

it('keeps the calendar date of activity_date given in the user timezone', function () {
    $activity = Activity::factory()->create([
        'activity_date' => CarbonImmutable::parse('2026-09-15 00:30', 'Asia/Jakarta'),
    ]);

    expect($activity->fresh()->activity_date->toDateString())->toBe('2026-09-15');
});
