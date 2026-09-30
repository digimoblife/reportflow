<?php

use App\Enums\InboundMessageStatus;
use App\Enums\OutcomeState;
use App\Models\Activity;
use App\Models\AiInteraction;
use App\Models\InboundMessage;
use App\Models\Task;
use App\Services\Worklog\Extraction\ItemDecision;
use App\Services\Worklog\WorklogService;
use Illuminate\Support\Carbon;
use Tests\Support\Extraction;

function storeMessage(array $w, string $text): InboundMessage
{
    return InboundMessage::factory()->create(['text' => $text, 'user_id' => $w['user']->id]);
}

it('turns a note into a validated proposal and applies only what was accepted', function () {
    $w = worklogWorld();
    $message = storeMessage($w, 'Harbor Portal: webhook tracking sudah selesai');
    fakeAi()->respondWith(Extraction::json([
        Extraction::item($w['tracking']->id, $w['harbor']->id, ['status_change' => ['from' => 'in_progress', 'to' => 'completed'], 'people' => ['Doni']]),
        Extraction::newItem($w['harbor']->id, 'Carrier onboarding', ['confidence' => 0.6]),
    ]));
    $tasksBefore = Task::query()->count();
    $activitiesBefore = Activity::query()->count();

    $result = app(WorklogService::class)->process($message);

    expect($result->proposal->summary())->toBe(['accepted' => 1, 'needs_confirmation' => 1])
        ->and($result->proposal->items[0]->statusChange)->toBe(['from' => 'in_progress', 'to' => 'completed'])
        ->and($result->proposal->items[0]->explicitTerminal)->toBeTrue()
        ->and($result->proposal->items[1]->decision)->toBe(ItemDecision::NeedsConfirmation)
        ->and($result->outcome->count(OutcomeState::Applied))->toBe(2)     // the low-confidence NEW task is safe and undoable: applied too
        ->and($result->outcome->count(OutcomeState::Pending))->toBe(0)
        ->and(Task::query()->count())->toBe($tasksBefore + 1)
        ->and(Activity::query()->count())->toBe($activitiesBefore + 2)
        ->and($message->fresh()->status)->toBe(InboundMessageStatus::Received)   // status handling stays in the job
        ->and(AiInteraction::query()->where('inbound_message_id', $message->id)->count())->toBe(1);
});

it('lets the validator refuse what the model invented', function () {
    $w = worklogWorld();
    fakeAi()->respondWith(Extraction::json([Extraction::item($w['strangerTask']->id, $w['harbor']->id)]));

    $result = app(WorklogService::class)->process(storeMessage($w, 'Harbor Portal update'));

    expect($result->proposal->items[0]->decision)->toBe(ItemDecision::Rejected)
        ->and($result->proposal->items[0]->reasons)->toBe(['task_ref_not_in_candidates']);
});

it('returns an empty proposal for chit-chat', function () {
    $w = worklogWorld();

    $result = app(WorklogService::class)->process(storeMessage($w, 'terima kasih ya'));

    expect($result->proposal->isEmpty())->toBeTrue();
});

it('resolves "today" in the user timezone', function () {
    $w = worklogWorld();
    $w['user']->update(['timezone' => 'Pacific/Kiritimati']); // UTC+14
    Carbon::setTestNow('2026-09-30 12:00:00 UTC');                // 2026-10-01 02:00 there
    fakeAi()->respondWith(Extraction::json([Extraction::newItem($w['harbor']->id, 'Late night task', ['activity' => ['date' => '2026-10-01']])]));

    $result = app(WorklogService::class)->process(storeMessage($w, 'Harbor Portal: task baru'));
    Carbon::setTestNow();

    expect(json_decode(fakeAi()->requests[0]->user, true)['today'])->toBe('2026-10-01')
        ->and($result->proposal->items[0]->decision)->toBe(ItemDecision::Accepted);
});
