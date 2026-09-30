<?php

use App\Enums\CorrectionType;
use App\Enums\InboundMessageStatus;
use App\Enums\OutcomeState;
use App\Models\Activity;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Task;
use App\Services\Telegram\CallbackData;
use App\Services\Telegram\TelegramApiException;
use App\Services\Worklog\Outcome;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Tests\Support\Extraction;

beforeEach(function () {
    config(['queue.default' => 'database']);
    Carbon::setTestNow(Carbon::create(2026, 9, 30, 10, 0, 0, 'Asia/Jakarta'));
    $this->tg = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta']);
    $this->w = worklogWorld($this->tg);
});

afterEach(fn () => Carbon::setTestNow());

/** Run the worker, then restore the test's user context (the worker flushes scoped instances). */
function settleWorker(): void
{
    runWorker();
    app(UserContext::class)->set(test()->tg->id);
}

/** Send a note, let the worker process it, return the stored message. */
function processNote(string $text, int $messageId = 100): InboundMessage
{
    send($text, $messageId);
    settleWorker();

    return storedMessages()->firstWhere('telegram_message_id', $messageId);
}

function outcomeOf(InboundMessage $message): Outcome
{
    return Outcome::fromArray(asSystem(fn () => InboundMessage::query()->findOrFail($message->id))->outcome);
}

/** Press a button (goes through the real webhook). */
function press(InboundMessage $message, string $action, ?int $item = null, ?string $arg = null, string $callbackId = 'cb-1', int $bubble = 900): void
{
    postTelegram(callbackPayload((new CallbackData($message->id, $action, $item, $arg))->encode(), 555001, $callbackId, $bubble))->assertOk();
}

function callbacksIn(array $keyboard): array
{
    return collect($keyboard)->flatMap(fn ($row) => $row)->map(fn ($b) => CallbackData::parse($b['callback_data']))->all();
}

describe('confirmation message', function () {
    it('spells out project, task, activity and status, with the four correction buttons', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix', 'summary' => 'Fixed the doubled total']])]));

        $message = processNote('Harbor Portal: total invoice sudah bener');
        $edit = fakeTelegram()->edits[0];

        expect(explode("\n", $edit['text'])[0])->toBeIn(variants('worklog.recorded'))
            ->and($edit['text'])->toContain('📁 Harbor Portal → Invoice PDF Export Bug')
            ->and($edit['text'])->toContain('🛠 Aktivitas: Perbaikan bug — Fixed the doubled total')
            ->and($edit['text'])->toContain('🔄 Status: Baru → Sedang dikerjakan')
            ->and($edit['message_id'])->toBe($message->reply_message_id)
            ->and(collect(callbacksIn($edit['keyboard']))->map(fn ($c) => $c->action.':'.$c->item)->all())->toBe(['undo:0', 'move:0', 'status:0', 'project:0'])
            ->and(collect($edit['keyboard'])->pluck(0)->pluck('text')->all())->toBe(['↩️ Undo', '✏️ Ubah Status'])
            ->and(outcomeOf($message)->confirmationMessageId)->toBe($message->reply_message_id)
            ->and($message->fresh()->status)->toBe(InboundMessageStatus::Processed);
    });

    it('shouts Completed and marks new and reopened tasks', function () {
        fakeAi()->respondWith(Extraction::json([
            Extraction::item($this->w['tracking']->id, $this->w['harbor']->id, ['status_change' => ['from' => 'in_progress', 'to' => 'completed']]),
            Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding'),
            Extraction::item($this->w['dock']->id, $this->w['harbor']->id, ['status_change' => ['from' => 'completed', 'to' => 'in_progress']]),
        ]));

        processNote('Harbor Portal: beberapa hal');
        $text = fakeTelegram()->edits[0]['text'];

        expect($text)->toContain('Sedang dikerjakan → SELESAI ✅')
            ->and($text)->toContain('→ Carrier onboarding (baru)')
            ->and($text)->toContain('SELESAI ✅ → Sedang dikerjakan (dibuka lagi)')
            ->and($text)->toContain('1) 📁')->toContain('3) 📁');
    });

    it('numbers several items, gives each its own button row and an "undo all"', function () {
        fakeAi()->respondWith(Extraction::json([
            Extraction::item($this->w['tracking']->id, $this->w['harbor']->id),
            Extraction::item($this->w['invoice']->id, $this->w['harbor']->id),
        ]));

        processNote('Harbor Portal dua hal');
        $keyboard = fakeTelegram()->edits[0]['keyboard'];

        expect($keyboard)->toHaveCount(3)
            ->and(collect($keyboard[0])->pluck('text')->all())->toBe(['1 ↩️', '1 🔀', '1 ✏️', '1 📁'])
            ->and(collect($keyboard[1])->pluck('text')->all())->toBe(['2 ↩️', '2 🔀', '2 ✏️', '2 📁'])
            ->and(callbacksIn([$keyboard[1]])[0]->item)->toBe(1)
            ->and($keyboard[2][0]['text'])->toBe('↩️ Undo semua')
            ->and(callbacksIn([$keyboard[2]])[0]->item)->toBeNull();
    });

    it('speaks English to an English note', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['tracking']->id, $this->w['harbor']->id, ['status_change' => ['from' => 'in_progress', 'to' => 'completed']])]));

        processNote('Finished the tracking work in Harbor Portal today and we are done with it');
        $text = fakeTelegram()->edits[0]['text'];

        expect(explode("\n", $text)[0])->toBeIn(variants('worklog.recorded', 'en'))
            ->and($text)->toContain('Activity:')->toContain('In progress → COMPLETED ✅')
            ->and(fakeTelegram()->edits[0]['keyboard'][0][0]['text'])->toBe('↩️ Undo');
    });

    it('shows the date when it is not today', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['tracking']->id, $this->w['harbor']->id, ['activity' => ['date' => '2026-09-25']])]));

        processNote('Harbor Portal minggu lalu');

        expect(fakeTelegram()->edits[0]['text'])->toContain('📅 25 Sep 2026');
    });

    it('reports rejected items and asks to split a long message', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['tracking']->id, $this->w['harbor']->id), Extraction::item(999_999, $this->w['harbor']->id)]));
        processNote('Harbor Portal x', 101);
        $rejectedText = fakeTelegram()->edits[0]['text'];

        fakeAi()->respondWith(Extraction::json(array_fill(0, 6, Extraction::item($this->w['tracking']->id, $this->w['harbor']->id))));
        processNote('Harbor Portal enam hal', 102);
        $splitEdit = fakeTelegram()->edits[1];

        expect($rejectedText)->toMatch('/\b1 catatan\b/')
            ->and($splitEdit['text'])->toBeIn(variants('worklog.split_required'))->and($splitEdit['keyboard'])->toBe([])
            ->and(storedMessages()->last()->status)->toBe(InboundMessageStatus::NeedsClarification)
            ->and(Activity::query()->where('inbound_message_id', storedMessages()->last()->id)->count())->toBe(0);
    });

    it('says so when there was nothing to record', function () {
        processNote('terima kasih pak carik');

        expect(isVariantOf(fakeTelegram()->edits[0]['text'], 'worklog.nothing_recorded'))->toBeTrue()
            ->and(fakeTelegram()->edits[0]['keyboard'])->toBe([]);
    });
});

describe('clarification questions', function () {
    beforeEach(function () {
        fakeAi()->respondWith(Extraction::json([
            Extraction::item($this->w['tracking']->id, $this->w['harbor']->id, ['confidence' => 0.8, 'activity' => ['summary' => 'Maybe the tracking API']]),
        ]));
        $this->message = processNote('Harbor Portal: yang kemarin itu');
    });

    it('sends one question per pending item, remembers its message id and waits', function () {
        $question = fakeTelegram()->sent[1];   // [0] is the "⏳" acknowledgement

        expect($this->message->fresh()->status)->toBe(InboundMessageStatus::NeedsClarification)
            ->and($question['text'])->toContain('"Shipment Tracking API"')->toContain('📝 Maybe the tracking API')
            ->and(collect(callbacksIn($question['keyboard']))->map(fn ($c) => $c->action.':'.$c->arg)->all())->toContain('yes:', 'new:', 'skip:')
            ->and(collect(callbacksIn($question['keyboard']))->where('action', 'pick')->count())->toBeBetween(1, 3)
            ->and(outcomeOf($this->message)->item(0)->questionMessageId)->toBe($question['message_id'])
            ->and(Activity::query()->where('inbound_message_id', $this->message->id)->count())->toBe(0)
            ->and(fakeTelegram()->edits[0]['text'])->toMatch('/\b1 catatan\b/');
    });

    it('sends each question once even when the delivery is retried', function () {
        // Second run: the acknowledgement edit works but sending the question fails once with a 502.
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['confidence' => 0.6])]));
        send('Harbor Portal: soal invoice', 110);
        fakeTelegram()->failNextSend(TelegramApiException::fromResponse('sendMessage', 502, 'Bad Gateway'));   // the question, not the ack
        $sentBefore = count(fakeTelegram()->sent);

        settleWorker();      // processing works, question delivery released
        advance(15);
        settleWorker();

        $questions = array_values(array_filter(array_slice(fakeTelegram()->sent, $sentBefore), fn ($m) => $m['keyboard'] !== null));
        expect($questions)->toHaveCount(1);
    });

    it('applies the proposed task on "yes" and turns the question into a confirmation with buttons', function () {
        $questionId = fakeTelegram()->sent[1]['message_id'];
        $before = $this->w['tracking']->fresh()->version;

        press($this->message, 'yes', 0, bubble: $questionId);

        $edit = collect(fakeTelegram()->edits)->last();
        expect(Activity::query()->where('inbound_message_id', $this->message->id)->where('task_id', $this->w['tracking']->id)->count())->toBe(1)
            ->and($this->w['tracking']->fresh()->version)->toBeGreaterThan($before)
            ->and($edit['message_id'])->toBe($questionId)
            ->and($edit['text'])->toContain('📁 Harbor Portal → Shipment Tracking API')
            ->and(callbacksIn($edit['keyboard'])[0]->action)->toBe('undo')
            ->and(outcomeOf($this->message)->item(0)->state)->toBe(OutcomeState::Applied)
            ->and($this->message->fresh()->status)->toBe(InboundMessageStatus::Processed)
            ->and(Correction::query()->count())->toBe(0)
            ->and(fakeTelegram()->answers)->not->toBeEmpty();
    });

    it('creates a new task on "new" and records the correction', function () {
        $tasks = Task::query()->count();

        press($this->message, 'new', 0);

        $item = outcomeOf($this->message)->item(0);
        expect(Task::query()->count())->toBe($tasks + 1)->and($item->createdTask)->toBeTrue()->and($item->taskId)->not->toBe($this->w['tracking']->id)
            ->and(Correction::query()->sole()->correction_type)->toBe(CorrectionType::MoveTask);
    });

    it('files the note under another candidate on "pick" and refuses a task that was not offered', function () {
        $offered = outcomeOf($this->message)->item(0)->options;

        press($this->message, 'pick', 0, (string) $this->w['strangerTask']->id, 'cb-a');
        expect(Activity::query()->where('inbound_message_id', $this->message->id)->count())->toBe(0);

        press($this->message, 'pick', 0, (string) $offered[0], 'cb-b');
        expect(outcomeOf($this->message)->item(0)->taskId)->toBe($offered[0])
            ->and(Correction::query()->sole()->after)->toBe(['task_id' => $offered[0]]);
    });

    it('cancels on "skip": nothing is written and the message is done', function () {
        press($this->message, 'skip', 0);

        expect(outcomeOf($this->message)->item(0)->state)->toBe(OutcomeState::Skipped)
            ->and(Activity::query()->where('inbound_message_id', $this->message->id)->count())->toBe(0)
            ->and($this->message->fresh()->status)->toBe(InboundMessageStatus::Processed)
            ->and(isVariantOf(collect(fakeTelegram()->edits)->last()['text'], 'question.cancelled'))->toBeTrue()
            ->and(collect(fakeTelegram()->edits)->last()['keyboard'])->toBe([]);
    });

    it('answers "already answered" to a second press and writes nothing twice', function () {
        press($this->message, 'yes', 0, callbackId: 'cb-1');
        $activities = Activity::query()->count();

        press($this->message, 'yes', 0, callbackId: 'cb-2');

        expect(Activity::query()->count())->toBe($activities)
            ->and(isVariantOf(collect(fakeTelegram()->answers)->last()['text'], 'callback.answered'))->toBeTrue();
    });

    it('does not let another user answer', function () {
        registerTelegramUser(777001);

        postTelegram(callbackPayload((new CallbackData($this->message->id, 'yes', 0))->encode(), 777001))->assertOk();

        expect(Activity::query()->where('inbound_message_id', $this->message->id)->count())->toBe(0)
            ->and(outcomeOf($this->message)->item(0)->state)->toBe(OutcomeState::Pending);
    });
});

describe('other questions', function () {
    it('asks for the project and files a new task there', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::newItem(null, 'Mystery task')]));
        $message = processNote('catatan tanpa project', 120);
        $question = fakeTelegram()->sent[1];

        expect($question['text'])->toContain(variants('question.project')[0] === explode("\n", $question['text'])[0] ? explode("\n", $question['text'])[0] : variants('question.project')[1]);
        $projectButtons = collect(callbacksIn($question['keyboard']))->where('action', 'proj');
        expect($projectButtons->count())->toBe(2);   // Harbor Portal + Kedai App; the archived one is not offered

        press($message, 'proj', 0, (string) $this->w['kedai']->id, 'cb-p');

        $item = outcomeOf($message)->item(0);
        expect(Task::query()->findOrFail($item->taskId)->project_id)->toBe($this->w['kedai']->id)
            ->and(Correction::query()->sole()->correction_type)->toBe(CorrectionType::ChangeProject);
    });

    it('does not accept an archived project or another user\'s project', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::newItem(null, 'Mystery task')]));
        $message = processNote('catatan tanpa project', 121);
        $taskCount = Task::query()->count();

        press($message, 'proj', 0, (string) $this->w['archived']->id, 'cb-1');
        press($message, 'proj', 0, (string) $this->w['strangerTask']->project_id, 'cb-2');

        expect(Task::query()->count())->toBe($taskCount)->and(outcomeOf($message)->item(0)->state)->toBe(OutcomeState::Pending);
    });

    it('asks about an old date and takes the date the user chooses', function (string $choice, string $expectedDate) {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['tracking']->id, $this->w['harbor']->id, ['activity' => ['date' => '2026-08-01']])]));
        $message = processNote('Harbor Portal: awal Agustus', 130 + strlen($choice));

        expect(fakeTelegram()->sent[array_key_last(fakeTelegram()->sent)]['text'])->toContain('1 Agt 2026');

        press($message, 'date', 0, $choice);

        $item = outcomeOf($message)->item(0);
        expect(Activity::query()->findOrFail($item->activityId)->activity_date->format('Y-m-d'))->toBe($expectedDate)
            ->and(Correction::query()->count())->toBe($choice === 'today' ? 1 : 0);
    })->with([['keep', '2026-08-01'], ['today', '2026-09-30']]);

    it('leaves the message waiting while another item is still pending', function () {
        fakeAi()->respondWith(Extraction::json([
            Extraction::item($this->w['tracking']->id, $this->w['harbor']->id, ['confidence' => 0.8]),
            Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['confidence' => 0.8]),
        ]));
        $message = processNote('Harbor Portal: dua yang ragu', 140);

        press($message, 'yes', 0, callbackId: 'cb-1');
        expect($message->fresh()->status)->toBe(InboundMessageStatus::NeedsClarification);

        press($message, 'yes', 1, callbackId: 'cb-2');
        expect($message->fresh()->status)->toBe(InboundMessageStatus::Processed)
            ->and(Activity::query()->where('inbound_message_id', $message->id)->count())->toBe(2);
    });
});
