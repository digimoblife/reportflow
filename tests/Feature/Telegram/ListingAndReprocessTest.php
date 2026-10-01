<?php

use App\Enums\CorrectionType;
use App\Enums\InboundMessageStatus;
use App\Enums\OutcomeState;
use App\Models\Activity;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Task;
use App\Services\Telegram\CallbackData;
use App\Services\Telegram\ViewData;
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

function lastSent(): array
{
    return collect(fakeTelegram()->sent)->last();
}

describe('/projects and /project', function () {
    it('lists active projects with their active task counts', function () {
        send('/projects', 10);

        $text = lastSent()['text'];
        expect($text)->toContain('📁 Harbor Portal — 4 task aktif')
            ->and($text)->toContain('📁 Kedai App — 1 task aktif')
            ->and($text)->not->toContain('Old Thing');
    });

    it('shows one project by name or alias, and a picker for ambiguous words', function () {
        send('/project HP', 10);
        expect(lastSent()['text'])->toContain('📁 Harbor Portal')->and(lastSent()['text'])->toContain('Invoice PDF Export Bug');

        send('/project a', 11);   // matches Harbor Portal and Kedai App
        expect(callbacksViews(lastSent()['keyboard']))->toHaveCount(2);

        send('/project zzz', 12);
        expect(isVariantOf(lastSent()['text'], 'list.project_not_found'))->toBeTrue();
    });
});

function callbacksViews(array $keyboard): array
{
    return collect($keyboard)->flatMap(fn ($row) => $row)->map(fn ($b) => ViewData::parse($b['callback_data']))->filter()->values()->all();
}

describe('/tasks and /task', function () {
    it('pages active tasks within the message limit', function () {
        foreach (range(1, 30) as $i) {
            Task::factory()->for($this->w['harbor'])->create(['title' => str_repeat('Long title ', 8).$i, 'status' => 'open']);
        }

        send('/tasks', 10);
        $first = lastSent();
        expect(mb_strlen($first['text']))->toBeLessThan(4096)
            ->and(collect(callbacksViews($first['keyboard']))->map->encode()->all())->toBe(['v:tasks:1']);

        postTelegram(callbackPayload('v:tasks:1', 555001, 'cb-p', 901))->assertOk();
        $edit = collect(fakeTelegram()->edits)->last();
        expect($edit['message_id'])->toBe(901)
            ->and($edit['text'])->toContain('2/')
            ->and(collect(callbacksViews($edit['keyboard']))->map->encode()->sort()->values()->all())->toContain('v:tasks:0');
    });

    it('shows a task with its timeline and latest three notes', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix', 'summary' => 'Fixed the doubled total']])]));
        processNote('Harbor Portal: invoice bug beres');

        send('/task '.$this->w['invoice']->id, 11);

        $text = lastSent()['text'];
        expect($text)->toContain('Invoice PDF Export Bug')
            ->and($text)->toContain('Riwayat:')
            ->and($text)->toContain('status berubah: Baru → Sedang dikerjakan')
            ->and($text)->toContain('Perbaikan bug: Fixed the doubled total');
    });

    it('finds by title word, offers a picker for several, and explains usage', function () {
        send('/task invoice', 10);
        expect(lastSent()['text'])->toContain('Invoice PDF Export Bug');

        send('/task dock', 11);   // Dock Sensor Feed + Dock Utilization Report
        expect(callbacksViews(lastSent()['keyboard']))->toHaveCount(2);

        send('/task', 12);
        expect(isVariantOf(lastSent()['text'], 'list.task_usage'))->toBeTrue();

        send('/task nothingmatches', 13);
        expect(isVariantOf(lastSent()['text'], 'list.task_not_found'))->toBeTrue();
    });

    it('never shows another user\'s task, even by id', function () {
        send('/task '.$this->w['strangerTask']->id, 10);

        expect(isVariantOf(lastSent()['text'], 'list.task_not_found'))->toBeTrue();
    });

    it('treats LIKE wildcards in the search word literally', function () {
        send('/task %', 10);

        expect(isVariantOf(lastSent()['text'], 'list.task_not_found'))->toBeTrue();
    });
});

describe('/inbox and reprocessing', function () {
    it('lists failed and waiting messages, and says when it is clean', function () {
        send('/inbox', 9);
        expect(isVariantOf(lastSent()['text'], 'list.inbox_empty'))->toBeTrue();

        $failed = InboundMessage::factory()->create(['user_id' => $this->tg->id, 'text' => 'catatan gagal', 'status' => InboundMessageStatus::Failed, 'telegram_chat_id' => 555001]);
        send('/inbox', 10);

        $sent = lastSent();
        expect($sent['text'])->toContain('catatan gagal')->and($sent['text'])->toContain('gagal diproses')
            ->and(CallbackData::parse($sent['keyboard'][0][0]['callback_data'])->action)->toBe('redo')
            ->and(CallbackData::parse($sent['keyboard'][0][0]['callback_data'])->inboundMessageId)->toBe($failed->id);
    });

    it('reprocesses a failed message once and delivers a new confirmation', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));
        $message = processNote('Harbor Portal: task baru carrier onboarding');
        asSystem(fn () => InboundMessage::query()->whereKey($message->id)->update(['status' => InboundMessageStatus::Failed, 'outcome' => null]));
        $edits = count(fakeTelegram()->edits);

        press($message, 'redo', null, null, 'cb-r1');
        press($message, 'redo', null, null, 'cb-r2');   // double tap while queued
        settleWorker();

        $fresh = asSystem(fn () => InboundMessage::query()->findOrFail($message->id));
        expect($fresh->status)->toBe(InboundMessageStatus::Processed)
            ->and($fresh->reprocess_count)->toBe(1)
            ->and(Task::query()->where('title', 'Carrier onboarding')->count())->toBe(2)   // 1 from the first run (failed state faked), 1 new
            ->and(collect(fakeTelegram()->edits)->slice($edits)->last()['text'])->toContain('Carrier onboarding');
    });

    it('undoes what the old run wrote before processing again, without duplicating notes', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix']])]));
        $message = processNote('Harbor Portal: invoice bug dikerjakan');
        $bubble = outcomeOf($message)->confirmationMessageId;

        press($message, 'redo', null, null, 'cb-r1', $bubble);
        settleWorker();

        expect(Activity::query()->where('task_id', $this->w['invoice']->id)->where('inbound_message_id', $message->id)->count())->toBe(1)
            ->and(Activity::withTrashed()->where('inbound_message_id', $message->id)->count())->toBe(2)
            ->and(Correction::query()->where('correction_type', CorrectionType::Undo)->count())->toBe(1)
            ->and(outcomeOf($message)->items[0]->state)->toBe(OutcomeState::Applied)
            ->and($this->w['invoice']->fresh()->status->value)->toBe('in_progress');
    });

    it('closes the old question bubbles so they cannot answer the new run', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['tracking']->id, $this->w['harbor']->id, ['confidence' => 0.8])]));
        $message = processNote('Harbor Portal: tracking');
        $questionId = outcomeOf($message)->items[0]->questionMessageId;
        expect($questionId)->not->toBeNull();

        press($message, 'redo', null, null, 'cb-r1');

        $edit = collect(fakeTelegram()->edits)->firstWhere('message_id', $questionId);
        expect(isVariantOf($edit['text'], 'reprocess.superseded'))->toBeTrue()
            ->and($edit['keyboard'])->toBe([]);
    });

    it('refuses to reprocess another user\'s message', function () {
        $other = registerTelegramUser(777002, ['timezone' => 'Asia/Jakarta']);
        $theirs = asUser($other->id, fn () => InboundMessage::factory()->create(['user_id' => $other->id, 'status' => InboundMessageStatus::Failed]));

        postTelegram(callbackPayload((new CallbackData($theirs->id, 'redo'))->encode(), 555001, 'cb-x'))->assertOk();

        expect(asSystem(fn () => InboundMessage::query()->findOrFail($theirs->id))->status)->toBe(InboundMessageStatus::Failed);
    });
});

describe('edited messages', function () {
    it('offers reprocess/keep after a finished message is edited, and reprocess uses the new text', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));
        $message = processNote('Harbor Portal: task baru', 100);

        postTelegram(TelegramPayload::edited('Harbor Portal: task baru carrier onboarding', messageId: 100, editDate: 1_780_000_200))->assertOk();
        $offer = lastSent();

        expect(isVariantOf($offer['text'], 'worklog.edit_saved_notice'))->toBeTrue()
            ->and(collect($offer['keyboard'][0])->map(fn ($b) => CallbackData::parse($b['callback_data'])->action)->all())->toBe(['redo', 'keep']);

        $redo = CallbackData::parse($offer['keyboard'][0][0]['callback_data']);
        postTelegram(callbackPayload($redo->encode(), 555001, 'cb-e1', 902))->assertOk();
        settleWorker();

        expect(asSystem(fn () => InboundMessage::query()->findOrFail($message->id))->text)->toBe('Harbor Portal: task baru carrier onboarding')
            ->and(storedMessages()->firstWhere('telegram_message_id', 100)->reprocess_count)->toBe(1);
    });

    it('"keep" changes nothing and closes the offer', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));
        $message = processNote('Harbor Portal: task baru', 100);

        press($message, 'keep', null, null, 'cb-k1', 903);

        $edit = collect(fakeTelegram()->edits)->last();
        expect($edit['message_id'])->toBe(903)
            ->and(isVariantOf($edit['text'], 'reprocess.kept'))->toBeTrue()
            ->and(storedMessages()->firstWhere('telegram_message_id', 100)->reprocess_count)->toBe(0);
    });
});
