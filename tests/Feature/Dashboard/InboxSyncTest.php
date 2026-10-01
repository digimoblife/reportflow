<?php

use App\Enums\InboundMessageStatus;
use App\Enums\MessageSource;
use App\Enums\OutcomeState;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\WorklogInput;
use App\Jobs\SyncTelegramBubbles;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Extraction;

beforeEach(function () {
    config(['queue.default' => 'database']);
    Carbon::setTestNow(Carbon::create(2026, 9, 30, 10, 0, 0, 'Asia/Jakarta'));
    $this->tg = $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta']);
    $this->w = worklogWorld($this->tg);
    $this->actingAs($this->user);
    app()->setLocale('id');
});

afterEach(fn () => Carbon::setTestNow());

/** A Telegram note whose only item waits for a "which task?" answer. */
function pendingNote(int $messageId = 100): InboundMessage
{
    fakeAi()->respondWith(Extraction::json([Extraction::item(test()->w['tracking']->id, test()->w['harbor']->id, ['confidence' => 0.8])]));

    return processNote('Harbor Portal: tracking', $messageId);
}

describe('inbox list', function () {
    it('shows failed and waiting notes of both channels, hides finished ones and other users\' notes', function () {
        $waiting = pendingNote();
        $failed = InboundMessage::factory()->create(['user_id' => $this->user->id, 'text' => 'catatan gagal dashboard', 'source' => 'dashboard', 'status' => InboundMessageStatus::Failed, 'idempotency_key' => 'dashboard:x:1']);
        InboundMessage::factory()->create(['user_id' => $this->user->id, 'text' => 'catatan beres', 'status' => InboundMessageStatus::Processed]);
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        asUser($other->id, fn () => InboundMessage::factory()->create(['user_id' => $other->id, 'text' => 'punya orang lain', 'status' => InboundMessageStatus::Failed]));

        Livewire::test(Inbox::class)
            ->assertSee('Harbor Portal: tracking')->assertSee('catatan gagal dashboard')
            ->assertSee('Shipment Tracking API')            // the question names the proposed task
            ->assertDontSee('catatan beres')->assertDontSee('punya orang lain')
            ->assertSeeHtml('wire:poll.5s');
    });

    it('says when it is empty', function () {
        Livewire::test(Inbox::class)->assertSee('Beres, tidak ada yang tertahan');
    });
});

describe('answering from the dashboard', function () {
    it('writes the item once, closes the Telegram question bubble and refreshes the confirmation', function () {
        $message = pendingNote();
        $outcome = outcomeOf($message);
        $questionBubble = $outcome->items[0]->questionMessageId;
        $edits = count(fakeTelegram()->edits);

        Livewire::test(Inbox::class)->call('answer', $message->id, 0, 'yes')->assertSee('Jawaban disimpan');
        settleWorker();

        $bubbleEdit = collect(fakeTelegram()->edits)->slice($edits)->firstWhere('message_id', $questionBubble);
        expect(outcomeOf($message)->items[0]->state)->toBe(OutcomeState::Applied)
            ->and(Activity::query()->where('inbound_message_id', $message->id)->count())->toBe(1)
            ->and(isVariantOf(explode("\n", $bubbleEdit['text'])[0], 'sync.answered_via_dashboard'))->toBeTrue()
            ->and($message->fresh()->status)->toBe(InboundMessageStatus::Processed)
            ->and(collect(fakeTelegram()->edits)->slice($edits)->where('message_id', outcomeOf($message)->confirmationMessageId))->not->toBeEmpty();
    });

    it('is a no-op when Telegram answered first (and the other way round)', function () {
        $message = pendingNote();
        press($message, 'yes', 0, null, 'cb-1', outcomeOf($message)->items[0]->questionMessageId);
        settleWorker();

        Livewire::test(Inbox::class)->call('answer', $message->id, 0, 'yes')->assertSee('sudah dijawab');
        expect(Activity::query()->where('inbound_message_id', $message->id)->count())->toBe(1);

        $second = pendingNote(101);
        Livewire::test(Inbox::class)->call('answer', $second->id, 0, 'yes');
        press($second, 'yes', 0, null, 'cb-2', outcomeOf($second)->items[0]->questionMessageId);

        expect(Activity::query()->where('inbound_message_id', $second->id)->count())->toBe(1);
    });

    it('accepts an invalid answer as "nothing to do" without writing', function () {
        $message = pendingNote();

        Livewire::test(Inbox::class)->call('answer', $message->id, 0, 'pick', '999999')->assertSee('sudah dijawab');

        expect(outcomeOf($message)->items[0]->state)->toBe(OutcomeState::Pending);
    });

    it('does not queue Telegram edits for a note that came from the dashboard', function () {
        $message = InboundMessage::factory()->create(['user_id' => $this->user->id, 'source' => 'dashboard', 'telegram_chat_id' => null, 'telegram_message_id' => null, 'idempotency_key' => 'dashboard:x:9', 'status' => InboundMessageStatus::Processed]);
        Queue::fake();

        SyncTelegramBubbles::dispatchFor($message, SyncTelegramBubbles::REFRESH);

        Queue::assertNothingPushed();
    });

    it('cannot answer another user\'s note', function () {
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $theirs = asUser($other->id, fn () => InboundMessage::factory()->create(['user_id' => $other->id]));

        Livewire::test(Inbox::class)->call('answer', $theirs->id, 0, 'yes')->assertNotFound();
    });
});

describe('reprocess from the dashboard', function () {
    it('reprocesses a failed note once and closes the old Telegram bubbles', function () {
        $message = pendingNote();
        $questionBubble = outcomeOf($message)->items[0]->questionMessageId;
        $edits = count(fakeTelegram()->edits);

        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['tracking']->id, $this->w['harbor']->id)]));
        $page = Livewire::test(Inbox::class)->call('reprocess', $message->id);
        $page->call('reprocess', $message->id)->assertSee('sedang diproses');   // second click while queued
        settleWorker();

        $closed = collect(fakeTelegram()->edits)->slice($edits)->firstWhere('message_id', $questionBubble);
        expect(isVariantOf($closed['text'], 'reprocess.superseded'))->toBeTrue()
            ->and($message->fresh()->reprocess_count)->toBe(1)
            ->and(Activity::query()->where('inbound_message_id', $message->id)->count())->toBe(1);
    });
});

describe('undo from the dashboard reaches Telegram', function () {
    it('redraws the Telegram confirmation as cancelled', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id)]));
        $message = processNote('Harbor Portal: invoice bug', 100);
        $bubble = outcomeOf($message)->confirmationMessageId;

        Livewire::test(WorklogInput::class)->call('undo', $message->id, 0);
        settleWorker();

        $last = collect(fakeTelegram()->edits)->where('message_id', $bubble)->last();
        expect(isVariantOf($last['text'], 'undo.done'))->toBeTrue()->and($last['keyboard'])->toBe([]);
    });
});

it('lists a Telegram note and a dashboard note side by side on the worklog page', function () {
    $tgNote = pendingNote();
    InboundMessage::factory()->create(['user_id' => $this->user->id, 'text' => 'dari dashboard', 'source' => MessageSource::Dashboard, 'telegram_chat_id' => null, 'telegram_message_id' => null, 'idempotency_key' => 'dashboard:x:5']);

    Livewire::test(WorklogInput::class)->assertSee('Harbor Portal: tracking')->assertSee('dari dashboard')->assertSee('Telegram')->assertSee('Dashboard');
});
