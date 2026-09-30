<?php

use App\Enums\EventActor;
use App\Enums\OutcomeState;
use App\Enums\TaskEventType;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\Person;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Services\Worklog\CandidateBuilder;
use App\Services\Worklog\Extraction\ExtractionValidator;
use App\Services\Worklog\Outcome;
use App\Services\Worklog\ProposalApplier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Extraction;

/**
 * Validate the items as production would, then apply them.
 *
 * @param  list<array<string, mixed>>  $items
 */
function applyItems(array $w, array $items, string $note = 'Harbor Portal update'): array
{
    $message = InboundMessage::factory()->create(['user_id' => $w['user']->id, 'text' => $note]);
    $set = app(CandidateBuilder::class)->build($note, $w['today']);
    $proposal = app(ExtractionValidator::class)->validate(Extraction::payload($items), $set, $w['today']);
    $outcome = app(ProposalApplier::class)->apply($message, $proposal, $set);

    return [$outcome, $message->fresh(), $set];
}

function counts(): array
{
    return [Task::query()->count(), Activity::query()->count(), TaskEvent::query()->count()];
}

it('writes an activity for an accepted update and stores the outcome on the message', function () {
    $w = worklogWorld();
    $task = $w['tracking'];
    $versionBefore = $task->version;

    [$outcome, $message] = applyItems($w, [Extraction::item($task->id, $w['harbor']->id, ['activity' => ['type' => 'testing', 'summary' => 'Tested callbacks'], 'people' => ['Doni']])]);

    $item = $outcome->items[0];
    $activity = Activity::query()->findOrFail($item->activityId);

    expect($item->state)->toBe(OutcomeState::Applied)
        ->and($item->taskId)->toBe($task->id)->and($item->createdTask)->toBeFalse()
        ->and($activity->summary)->toBe('Tested callbacks')->and($activity->task_id)->toBe($task->id)->and($activity->inbound_message_id)->toBe($message->id)
        ->and($activity->activity_date->format('Y-m-d'))->toBe('2026-09-30')
        ->and($activity->source->value)->toBe('telegram')
        ->and($task->fresh()->version)->toBeGreaterThan($versionBefore)
        ->and($task->fresh()->last_activity_at->format('Y-m-d'))->toBeIn(['2026-09-29', '2026-09-30'])
        ->and($message->outcome['state'])->toBe('applied')
        ->and(Outcome::fromArray($message->outcome)->applied())->toHaveCount(1)
        ->and(TaskEvent::query()->where('task_id', $task->id)->count())->toBe(0);   // in_progress task + testing: no status change
});

it('moves an Open task to In Progress on real work, and only then (product rule)', function (string $type, bool $moves) {
    $w = worklogWorld();

    [$outcome] = applyItems($w, [Extraction::item($w['invoice']->id, $w['harbor']->id, ['activity' => ['type' => $type]])]);

    expect($w['invoice']->fresh()->status)->toBe($moves ? TaskStatus::InProgress : TaskStatus::Open)
        ->and($outcome->items[0]->statusTo)->toBe($moves ? 'in_progress' : null)
        ->and(TaskEvent::query()->where('task_id', $w['invoice']->id)->count())->toBe($moves ? 1 : 0);
})->with([
    ['development', true], ['bug_fix', true], ['testing', true], ['deployment', true], ['investigation', true], ['research', true], ['configuration', true],
    ['documentation', true], ['milestone', true], ['resolution', true],
    ['request', false], ['communication', false], ['follow_up', false], ['blocker', false], ['other', false],
]);

it('does not touch Waiting or Blocked tasks unless the note asks for it', function () {
    $w = worklogWorld();

    applyItems($w, [Extraction::item($w['sso']->id, $w['harbor']->id, ['activity' => ['type' => 'development']])]);

    expect($w['sso']->fresh()->status)->toBe(TaskStatus::Waiting);
});

it('creates new tasks with a sensible initial status and a created event', function (string $type, ?string $explicit, string $expected) {
    $w = worklogWorld();
    $override = ['activity' => ['type' => $type]] + ($explicit ? ['status_change' => ['from' => null, 'to' => $explicit]] : []);

    [$outcome] = applyItems($w, [Extraction::newItem($w['harbor']->id, 'Carrier onboarding', $override)]);

    $task = Task::query()->findOrFail($outcome->items[0]->taskId);
    $event = TaskEvent::query()->where('task_id', $task->id)->sole();

    expect($task->title)->toBe('Carrier onboarding')->and($task->status->value)->toBe($expected)->and($task->version)->toBeGreaterThanOrEqual(1)
        ->and($outcome->items[0]->createdTask)->toBeTrue()->and($outcome->items[0]->statusFrom)->toBeNull()->and($outcome->items[0]->statusTo)->toBe($expected)
        ->and($event->event_type)->toBe(TaskEventType::Created)->and($event->actor)->toBe(EventActor::Ai)
        ->and($event->inbound_message_id)->not->toBeNull();
})->with([
    'work -> in progress' => ['bug_fix', null, 'in_progress'],
    'request -> open' => ['request', null, 'open'],
    'communication -> open' => ['communication', null, 'open'],
    'explicit completed wins' => ['deployment', 'completed', 'completed'],
    'explicit waiting wins' => ['request', 'waiting', 'waiting'],
]);

it('applies explicit terminal statuses and names a reopen', function () {
    $w = worklogWorld();

    [$done] = applyItems($w, [Extraction::item($w['tracking']->id, $w['harbor']->id, ['status_change' => ['from' => 'in_progress', 'to' => 'completed']])]);
    [$reopen] = applyItems($w, [Extraction::item($w['dock']->id, $w['harbor']->id, ['status_change' => ['from' => 'completed', 'to' => 'in_progress']])]);

    expect($done->items[0]->statusFrom)->toBe('in_progress')->and($done->items[0]->statusTo)->toBe('completed')->and($w['tracking']->fresh()->completed_at)->not->toBeNull()
        ->and($reopen->items[0]->reopened)->toBeTrue()
        ->and(TaskEvent::query()->where('task_id', $w['dock']->id)->sole()->event_type)->toBe(TaskEventType::Reopened);
});

function pendingItem(string $scenario, array $w): array
{
    return match ($scenario) {
        'medium' => Extraction::item($w['tracking']->id, $w['harbor']->id, ['confidence' => 0.8]),
        'low' => Extraction::item($w['tracking']->id, $w['harbor']->id, ['confidence' => 0.4]),
        'contradiction' => Extraction::item($w['invoice']->id, $w['harbor']->id, ['people' => ['Zed']]),
        'no project' => Extraction::newItem(null),
        'old date' => Extraction::item($w['tracking']->id, $w['harbor']->id, ['activity' => ['date' => '2026-08-01']]),
    };
}

it('writes nothing for unsure items: they wait as pending with a question', function (string $scenario, string $question) {
    $w = worklogWorld();
    $before = counts();

    [$outcome, $message] = applyItems($w, [pendingItem($scenario, $w)]);

    $pending = $outcome->items[0];
    expect($pending->state)->toBe(OutcomeState::Pending)->and($pending->question)->toBe($question)->and($pending->pending)->not->toBeNull()
        ->and(counts())->toBe($before)
        ->and($outcome->hasPending())->toBeTrue()
        ->and($message->outcome['state'])->toBe('pending');
})->with([
    'medium confidence match' => ['medium', 'match'],
    'low confidence match' => ['low', 'match'],
    'person contradiction' => ['contradiction', 'match'],
    'no project for a new task' => ['no project', 'project'],
    'older than 30 days' => ['old date', 'date'],
]);

it('offers the other tasks of the same project as alternatives for a match question', function () {
    $w = worklogWorld();

    [$outcome] = applyItems($w, [Extraction::item($w['tracking']->id, $w['harbor']->id, ['confidence' => 0.75])]);

    expect($outcome->items[0]->options)->not->toContain($w['tracking']->id)
        ->and(count($outcome->items[0]->options))->toBeBetween(1, 3)
        ->and(Task::query()->whereIn('id', $outcome->items[0]->options)->pluck('project_id')->unique()->all())->toBe([$w['harbor']->id]);
});

it('applies a low-confidence NEW task, and the activity when only a status detail was questionable', function () {
    $w = worklogWorld();

    [$outcome] = applyItems($w, [
        Extraction::newItem($w['harbor']->id, 'Maybe a task', ['confidence' => 0.5]),
        Extraction::item($w['dock']->id, $w['harbor']->id, ['status_change' => ['from' => 'completed', 'to' => 'waiting']]),   // illegal transition
    ]);

    expect($outcome->items[0]->state)->toBe(OutcomeState::Applied)
        ->and($outcome->items[1]->state)->toBe(OutcomeState::Applied)
        ->and($outcome->items[1]->statusTo)->toBeNull()
        ->and($w['dock']->fresh()->status)->toBe(TaskStatus::Completed);
});

it('records rejected items and applies the others independently', function () {
    $w = worklogWorld();

    [$outcome] = applyItems($w, [
        Extraction::item(999_999, $w['harbor']->id),
        Extraction::item($w['tracking']->id, $w['harbor']->id),
        Extraction::item($w['tracking']->id, $w['harbor']->id, ['activity' => ['date' => '2027-01-01']]),
    ]);

    expect(array_map(fn ($i) => $i->state, $outcome->items))->toBe([OutcomeState::Rejected, OutcomeState::Applied, OutcomeState::Rejected])
        ->and($outcome->items[0]->reasons)->toBe(['task_ref_not_in_candidates'])
        ->and($outcome->items[2]->reasons)->toBe(['date_in_future'])
        ->and(Activity::query()->where('task_id', $w['tracking']->id)->where('inbound_message_id', '!=', null)->count())->toBe(1);
});

it('asks to split a message with more than five items and writes nothing', function () {
    $w = worklogWorld();
    $before = counts();
    $items = array_fill(0, 6, Extraction::item($w['tracking']->id, $w['harbor']->id));

    [$outcome] = applyItems($w, $items);

    expect($outcome->splitRequired)->toBeTrue()->and($outcome->hasPending())->toBeTrue()
        ->and(collect($outcome->items)->every(fn ($i) => $i->state === OutcomeState::Skipped))->toBeTrue()
        ->and(counts())->toBe($before);

    [$five] = applyItems($w, array_fill(0, 5, Extraction::item($w['tracking']->id, $w['harbor']->id)));
    expect($five->splitRequired)->toBeFalse()->and($five->count(OutcomeState::Applied))->toBe(5);
});

it('is idempotent: applying the same message twice writes once', function () {
    $w = worklogWorld();
    [$first, $message, $set] = applyItems($w, [Extraction::item($w['tracking']->id, $w['harbor']->id)]);
    $before = counts();
    $proposal = app(ExtractionValidator::class)->validate(Extraction::payload([Extraction::item($w['tracking']->id, $w['harbor']->id)]), $set, $w['today']);

    $second = app(ProposalApplier::class)->apply($message, $proposal, $set);

    expect($second->toArray())->toEqual($first->toArray())->and(counts())->toBe($before);
});

it('rolls everything back when one item fails', function () {
    $w = worklogWorld();
    $message = InboundMessage::factory()->create(['user_id' => $w['user']->id, 'text' => 'Harbor Portal']);
    $set = app(CandidateBuilder::class)->build('Harbor Portal', $w['today']);
    $proposal = app(ExtractionValidator::class)->validate(Extraction::payload([
        Extraction::item($w['tracking']->id, $w['harbor']->id),
        Extraction::item($w['invoice']->id, $w['harbor']->id),
    ]), $set, $w['today']);
    $before = counts();
    $w['invoice']->delete();   // the second task disappears between validation and writing

    expect(fn () => app(ProposalApplier::class)->apply($message, $proposal, $set))->toThrow(ModelNotFoundException::class);

    expect(Activity::query()->count())->toBe($before[1])->and($message->fresh()->outcome)->toBeNull();
});

it('links only people the user already has', function () {
    $w = worklogWorld();
    Person::factory()->create(['name' => 'Bima', 'aliases' => ['Bim']]);

    applyItems($w, [Extraction::item($w['invoice']->id, $w['harbor']->id, ['people' => ['bim', 'Rina', 'Nobody Here']])]);

    expect($w['invoice']->fresh()->people->pluck('name')->sort()->values()->all())->toBe(['Bima', 'Rina'])
        ->and(Person::query()->where('name', 'Nobody Here')->exists())->toBeFalse();
});

describe('answering a pending question', function () {
    it('applies to the proposed task, to another task, or as a new task', function () {
        $w = worklogWorld();
        [$outcome, $message] = applyItems($w, [Extraction::item($w['tracking']->id, $w['harbor']->id, ['confidence' => 0.75])]);
        $pending = $outcome->items[0];
        $before = counts();

        $yes = app(ProposalApplier::class)->applyPending($message, $pending, $w['tracking']->id);
        expect($yes->state)->toBe(OutcomeState::Applied)->and($yes->taskId)->toBe($w['tracking']->id)->and(counts()[1])->toBe($before[1] + 1)
            ->and(Outcome::fromArray($message->fresh()->outcome)->item(0)->state)->toBe(OutcomeState::Applied)
            ->and(Outcome::fromArray($message->fresh()->outcome)->hasPending())->toBeFalse();
    });

    it('creates a new task when the user says so', function () {
        $w = worklogWorld();
        [$outcome, $message] = applyItems($w, [Extraction::item($w['tracking']->id, $w['harbor']->id, ['confidence' => 0.5, 'activity' => ['type' => 'request']])]);
        $tasksBefore = Task::query()->count();

        $written = app(ProposalApplier::class)->applyPending($message, $outcome->items[0], null, $w['kedai']->id);

        expect($written->createdTask)->toBeTrue()->and($written->projectId)->toBe($w['kedai']->id)
            ->and(Task::query()->count())->toBe($tasksBefore + 1)
            ->and(Task::query()->findOrFail($written->taskId)->status)->toBe(TaskStatus::Open);
    });

    it('takes the date the user picked', function () {
        $w = worklogWorld();
        [$outcome, $message] = applyItems($w, [Extraction::item($w['tracking']->id, $w['harbor']->id, ['activity' => ['date' => '2026-08-01']])]);

        $written = app(ProposalApplier::class)->applyPending($message, $outcome->items[0], $w['tracking']->id, null, '2026-09-30');

        expect(Activity::query()->findOrFail($written->activityId)->activity_date->format('Y-m-d'))->toBe('2026-09-30');
    });
});
