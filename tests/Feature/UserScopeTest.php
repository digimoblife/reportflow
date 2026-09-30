<?php

use App\Exceptions\CrossUserWriteException;
use App\Exceptions\MissingUserContextException;
use App\Jobs\Middleware\WithUserContext;
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
use App\Models\TaskPerson;
use App\Models\User;
use App\Support\UserContext;

/**
 * Builds one full graph of records for the given user and returns [model class => id].
 *
 * @return array<class-string, int>
 */
function seedGraphFor(User $user): array
{
    return app(UserContext::class)->runAs($user->id, function () {
        $task = Task::factory()->create();
        $person = Person::factory()->create();
        $message = InboundMessage::factory()->create();
        $report = Report::factory()->create(['project_id' => $task->project_id]);
        $version = ReportVersion::factory()->for($report)->create();
        $rule = ReminderRule::factory()->create();

        return [
            Project::class => $task->project_id,
            Task::class => $task->id,
            Person::class => $person->id,
            TaskPerson::class => TaskPerson::factory()->create(['task_id' => $task->id, 'person_id' => $person->id])->id,
            InboundMessage::class => $message->id,
            Activity::class => Activity::factory()->for($task)->create()->id,
            TaskEvent::class => TaskEvent::factory()->for($task)->create()->id,
            Correction::class => Correction::factory()->create()->id,
            ReportTemplate::class => ReportTemplate::factory()->create()->id,
            Report::class => $report->id,
            ReportVersion::class => $version->id,
            ReportFile::class => ReportFile::factory()->for($version)->create()->id,
            ReminderRule::class => $rule->id,
            ReminderInstance::class => ReminderInstance::factory()->for($rule, 'rule')->create()->id,
            AiInteraction::class => AiInteraction::factory()->create()->id,
        ];
    });
}

it('only returns rows that belong to the acting user', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceIds = seedGraphFor($alice);
    $bobIds = seedGraphFor($bob);

    actingAsUser($alice);

    foreach ($aliceIds as $model => $id) {
        expect($model::query()->pluck('id')->all())->toBe([$id], "{$model} should only list Alice's row")
            ->and($model::find($bobIds[$model]))->toBeNull("{$model} must not expose Bob's row");
    }
});

it('fails closed when no user context is set', function (string $model) {
    expect(fn () => $model::query()->count())->toThrow(MissingUserContextException::class);
})->with([
    Project::class, Person::class, Task::class, TaskPerson::class, InboundMessage::class, Activity::class,
    TaskEvent::class, Correction::class, ReportTemplate::class, Report::class, ReportVersion::class,
    ReportFile::class, ReminderRule::class, ReminderInstance::class, AiInteraction::class,
]);

it('lets system code see every user explicitly', function () {
    seedGraphFor(User::factory()->create());
    seedGraphFor(User::factory()->create());

    $count = app(UserContext::class)->runAsSystem(fn () => Task::count());

    expect($count)->toBe(2)
        ->and(fn () => Task::count())->toThrow(MissingUserContextException::class);
});

it('fills user_id from the context on create and refuses writes for another user', function () {
    $alice = actingAsUser();
    $bob = User::factory()->create();

    $project = Project::create(['name' => 'Internal Tools', 'slug' => 'internal-tools']);

    expect($project->user_id)->toBe($alice->id)
        ->and(fn () => Project::create(['user_id' => $bob->id, 'name' => 'X', 'slug' => 'x']))
        ->toThrow(CrossUserWriteException::class);
});

it('restores the previous context after runAs, even when the callback throws', function () {
    $context = app(UserContext::class);
    $context->set(1);

    try {
        $context->runAs(2, fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect($context->userId())->toBe(1)->and($context->isSystem())->toBeFalse();
});

it('runs queued jobs as their user without leaking into the next job', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $aliceTask = seedGraphFor($alice)[Task::class];
    $bobTask = seedGraphFor($bob)[Task::class];

    $seen = [];
    $job = new stdClass;
    $handle = function () use (&$seen) {
        $seen[] = Task::pluck('id')->all();
    };

    (new WithUserContext($alice->id))->handle($job, $handle);
    (new WithUserContext($bob->id))->handle($job, $handle);

    expect($seen)->toBe([[$aliceTask], [$bobTask]])
        ->and(app(UserContext::class)->userId())->toBeNull()
        ->and(fn () => Task::count())->toThrow(MissingUserContextException::class);
});
