<?php

use App\Enums\CorrectionType;
use App\Enums\OutcomeState;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\AiInteraction;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Tests\Support\Extraction;
use Tests\Support\TelegramPayload;

beforeEach(function () {
    config(['queue.default' => 'database']);
    Carbon::setTestNow(Carbon::create(2026, 9, 30, 10, 0, 0, 'Asia/Jakarta'));
    $this->tg = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta']);
    $this->w = worklogWorld($this->tg);
});

afterEach(fn () => Carbon::setTestNow());

/** Reply to the confirmation bubble of $message with $text; returns the stored reply. */
function replyTo(InboundMessage $message, string $text, int $messageId = 200): InboundMessage
{
    postTelegram(TelegramPayload::message($text, messageId: $messageId, extra: ['reply_to_message' => ['message_id' => outcomeOf($message)->confirmationMessageId]]))->assertOk();
    settleWorker();

    return storedMessages()->firstWhere('telegram_message_id', $messageId);
}

it('replaces the old result with the corrected one, and records everything', function () {
    fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix']])]));
    $original = processNote('Harbor Portal: ngerjain bug pdf', 100);
    expect($this->w['invoice']->fresh()->status)->toBe(TaskStatus::InProgress);

    fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['tracking']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix', 'summary' => 'Fixed the PDF bug']])]));
    $reply = replyTo($original, 'bukan, itu untuk task Shipment Tracking API');

    expect($reply->correction_of_id)->toBe($original->id)
        ->and(Activity::query()->where('inbound_message_id', $original->id)->count())->toBe(0)
        ->and(Activity::query()->where('inbound_message_id', $reply->id)->where('task_id', $this->w['tracking']->id)->count())->toBe(1)
        ->and($this->w['invoice']->fresh()->status)->toBe(TaskStatus::Open)           // restored exactly
        ->and(outcomeOf($original)->items[0]->state)->toBe(OutcomeState::Undone)
        ->and(outcomeOf($reply)->items[0]->state)->toBe(OutcomeState::Applied)
        ->and(Correction::query()->where('correction_type', CorrectionType::Undo)->count())->toBe(1)
        ->and(AiInteraction::query()->where('purpose', 'worklog_correction')->count())->toBe(1)
        ->and(AiInteraction::query()->where('purpose', 'worklog_correction')->value('prompt_version'))->toBe('worklog_correction@v1');

    $edits = collect(fakeTelegram()->edits);
    expect(isVariantOf(explode("\n", $edits->firstWhere('message_id', outcomeOf($reply)->confirmationMessageId)['text'])[0], 'correction.reply_applied'))->toBeTrue()
        ->and(isVariantOf($edits->where('message_id', outcomeOf($original)->confirmationMessageId)->last()['text'], 'undo.done'))->toBeTrue();
});

it('sends the original note, the previous result and the reply to the AI, never the task the original created as a candidate', function () {
    fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));
    $original = processNote('Harbor Portal: task baru carrier onboarding', 100);
    $created = Task::query()->where('title', 'Carrier onboarding')->firstOrFail();

    fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['kedai']->id, 'Carrier onboarding')]));
    replyTo($original, 'project-nya Kedai App');

    $payload = json_decode(collect(fakeAi()->requests)->last()->user, true);
    expect($payload['original_message'])->toBe('Harbor Portal: task baru carrier onboarding')
        ->and($payload['correction'])->toBe('project-nya Kedai App')
        ->and($payload['previous_result'][0]['new_task'])->toBeTrue()
        ->and($payload['previous_result'][0]['task_id'])->toBeNull()
        ->and(collect($payload['candidates'])->pluck('id')->all())->not->toContain($created->id)
        ->and(Task::query()->where('title', 'Carrier onboarding')->sole()->project_id)->toBe($this->w['kedai']->id);
});

it('changes nothing when the reply is not a correction', function () {
    fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id)]));
    $original = processNote('Harbor Portal: invoice bug', 100);

    fakeAi()->respondWith(Extraction::json([]));
    $reply = replyTo($original, 'makasih ya');

    expect(outcomeOf($original)->items[0]->state)->toBe(OutcomeState::Applied)
        ->and(Activity::query()->where('inbound_message_id', $original->id)->count())->toBe(1)
        ->and(isVariantOf(collect(fakeTelegram()->edits)->last()['text'], 'correction.reply_unchanged'))->toBeTrue()
        ->and(Correction::query()->count())->toBe(0);
});

it('keeps the old result when the corrected item is rejected by the validator', function () {
    fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id)]));
    $original = processNote('Harbor Portal: invoice bug', 100);

    fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['strangerTask']->id, $this->w['harbor']->id)]));   // not a candidate
    replyTo($original, 'pindahkan ke task lain');

    expect(outcomeOf($original)->items[0]->state)->toBe(OutcomeState::Applied)
        ->and(Activity::query()->where('inbound_message_id', $original->id)->count())->toBe(1);
});

it('keeps the old result when the AI call fails, and keeps the reply', function () {
    fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id)]));
    $original = processNote('Harbor Portal: invoice bug', 100);

    fakeAi()->respondWith('not json at all');
    $reply = replyTo($original, 'salah task');

    expect(outcomeOf($original)->items[0]->state)->toBe(OutcomeState::Applied)
        ->and($reply->text)->toBe('salah task');
});

it('treats a reply to a confirmation with nothing applied, or to anything else, as a normal note', function () {
    fakeAi()->respondWith(Extraction::json([]));
    $empty = processNote('terima kasih', 100);

    postTelegram(TelegramPayload::message('Harbor Portal: tracking selesai', messageId: 201, extra: ['reply_to_message' => ['message_id' => $empty->reply_message_id]]))->assertOk();
    postTelegram(TelegramPayload::message('Harbor Portal: tracking lagi', messageId: 202, extra: ['reply_to_message' => ['message_id' => 99999]]))->assertOk();

    expect(storedMessages()->whereNotNull('correction_of_id'))->toHaveCount(0);
});

it('never lets a reply correct another user\'s message', function () {
    fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id)]));
    $original = processNote('Harbor Portal: invoice bug', 100);
    registerTelegramUser(777002, ['timezone' => 'Asia/Jakarta']);

    postTelegram(TelegramPayload::message('salah task', from: 777002, messageId: 300, extra: ['reply_to_message' => ['message_id' => outcomeOf($original)->confirmationMessageId]]))->assertOk();

    expect(outcomeOf($original)->items[0]->state)->toBe(OutcomeState::Applied)
        ->and(asSystem(fn () => InboundMessage::query()->whereNotNull('correction_of_id')->count()))->toBe(0);
});
