<?php

use App\Enums\CorrectionType;
use App\Enums\InboundMessageStatus;
use App\Enums\MessageSource;
use App\Enums\OutcomeState;
use App\Enums\TaskStatus;
use App\Exceptions\MissingUserContextException;
use App\Filament\Pages\WorklogInput;
use App\Jobs\ProcessInboundMessage;
use App\Models\Activity;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Task;
use App\Models\User;
use App\Services\Worklog\DashboardSubmission;
use App\Services\Worklog\Outcome;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Extraction;
use Tests\Support\FakeSecrets;

beforeEach(function () {
    config(['queue.default' => 'database']);
    Carbon::setTestNow(Carbon::create(2026, 9, 30, 10, 0, 0, 'Asia/Jakarta'));
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta']);
    $this->w = worklogWorld($this->user);
    $this->actingAs($this->user);
    app()->setLocale('id');   // the BindUserContext middleware does this per request
});

afterEach(fn () => Carbon::setTestNow());

/** Run the worker, then restore the user context (the worker flushes scoped instances). */
function settleDashboard(): void
{
    runWorker();
    app(UserContext::class)->set(test()->user->id);
}

function submitNote(string $text): Testable
{
    return Livewire::test(WorklogInput::class)->set('text', $text)->call('submit');
}

describe('submitting a note', function () {
    it('stores it as a dashboard message, processes it through the shared pipeline and shows the result', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix', 'summary' => 'Fixed the doubled total']])]));

        $page = submitNote('Harbor Portal: total invoice sudah bener');
        $page->assertSet('text', '');
        $message = InboundMessage::query()->sole();
        expect($message->source)->toBe(MessageSource::Dashboard)->and($message->telegram_chat_id)->toBeNull();

        settleDashboard();

        Livewire::test(WorklogInput::class)
            ->assertSee('Harbor Portal')->assertSee('Invoice PDF Export Bug')->assertSee('Fixed the doubled total')
            ->assertSee('Sedang dikerjakan')->assertSee('Selesai');
        expect($message->fresh()->status)->toBe(InboundMessageStatus::Processed)
            ->and($this->w['invoice']->fresh()->status)->toBe(TaskStatus::InProgress)
            ->and(fakeTelegram()->sent)->toBe([]);   // nothing is sent to Telegram for a dashboard note
    });

    it('creates exactly one entry for a double click or a network retry', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));

        $page = Livewire::test(WorklogInput::class)->set('text', 'Harbor Portal: task baru');
        $key = $page->get('submissionKey');
        $page->call('submit');
        $page->set('submissionKey', $key)->set('text', 'Harbor Portal: task baru')->call('submit');   // retry with the same key
        settleDashboard();

        expect(InboundMessage::query()->count())->toBe(1)
            ->and(Task::query()->where('title', 'Carrier onboarding')->count())->toBe(1)
            ->and(Activity::query()->where('inbound_message_id', InboundMessage::query()->value('id'))->count())->toBe(1);
    });

    it('takes a fresh key after success so the next note is a new entry', function () {
        Queue::fake();
        $page = Livewire::test(WorklogInput::class)->set('text', 'satu')->call('submit');
        $first = $page->get('submissionKey');
        $page->set('text', 'dua')->call('submit');

        expect(InboundMessage::query()->count())->toBe(2)->and($page->get('submissionKey'))->not->toBe($first);
    });

    it('does not store secrets, and fails closed when it cannot check the text', function () {
        Queue::fake();
        submitNote('deploy selesai, pakai kunci '.FakeSecrets::openAiKey())->assertSee('tidak disimpan');
        expect(InboundMessage::query()->sole()->text)->not->toContain(FakeSecrets::openAiKey());

        config(['redaction.max_input_length' => 20]);
        InboundMessage::query()->delete();
        submitNote(str_repeat('abc ', 30));
        expect(InboundMessage::query()->count())->toBe(0);
    });

    it('rejects empty and oversized notes without queueing', function () {
        Queue::fake();
        submitNote('   ')->assertSee('Tulis catatannya dulu');
        submitNote(str_repeat('a', DashboardSubmission::MAX_LENGTH + 1))->assertSee('terlalu panjang');

        expect(InboundMessage::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('requires a user context (fails closed)', function () {
        app(UserContext::class)->clear();

        expect(fn () => app(DashboardSubmission::class)->submit('x', 'abcdefgh-abcdefgh-abcdefgh'))->toThrow(MissingUserContextException::class);
    });
});

describe('Telegram notes appear in the list', function () {
    it('shows a processed Telegram message with its items, labelled by channel', function () {
        $tg = registerTelegramUser(777003, ['timezone' => 'Asia/Jakarta']);
        asUser($tg->id, fn () => InboundMessage::factory()->create(['user_id' => $tg->id, 'text' => 'catatan orang lain']));

        fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));
        InboundMessage::factory()->create(['user_id' => $this->user->id, 'text' => 'Harbor Portal: task baru', 'source' => 'telegram', 'telegram_chat_id' => 555001, 'telegram_message_id' => 9, 'idempotency_key' => 'telegram:555001:9']);
        ProcessInboundMessage::dispatch(InboundMessage::query()->sole()->id, $this->user->id);
        settleDashboard();

        Livewire::test(WorklogInput::class)->assertSee('Telegram')->assertSee('Carrier onboarding')->assertDontSee('catatan orang lain');
    });

    it('polls', function () {
        Livewire::test(WorklogInput::class)->assertSeeHtml('wire:poll.5s');
    });
});

describe('corrections from the dashboard use the same services', function () {
    beforeEach(function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix']])]));
        $this->page = submitNote('Harbor Portal: invoice bug');
        settleDashboard();
        $this->message = InboundMessage::query()->sole();
    });

    it('undoes an item and restores the task', function () {
        $this->page->call('undo', $this->message->id, 0);

        expect(Activity::query()->where('inbound_message_id', $this->message->id)->count())->toBe(0)
            ->and($this->w['invoice']->fresh()->status)->toBe(TaskStatus::Open)
            ->and(Outcome::fromArray($this->message->fresh()->outcome)->items[0]->state)->toBe(OutcomeState::Undone)
            ->and(Correction::query()->where('correction_type', CorrectionType::Undo)->count())->toBe(1);
    });

    it('offers only statuses the matrix allows, and applies one', function () {
        $page = Livewire::test(WorklogInput::class)->call('openPanel', 'status', $this->message->id, 0);

        expect(array_keys($page->instance()->choices()))->not->toContain('draft', 'in_progress')->toContain('completed');

        $page->set('choice', 'completed')->call('applyPanel');
        expect($this->w['invoice']->fresh()->status)->toBe(TaskStatus::Completed)
            ->and(Correction::query()->where('correction_type', CorrectionType::ChangeStatus)->count())->toBe(1);
    });

    it('ignores a forbidden status even if forged', function () {
        Livewire::test(WorklogInput::class)->call('openPanel', 'status', $this->message->id, 0)->set('choice', 'draft')->call('applyPanel')->assertSee('tidak bisa dilakukan');

        expect($this->w['invoice']->fresh()->status)->toBe(TaskStatus::InProgress);
    });

    it('moves the note to another task', function () {
        Livewire::test(WorklogInput::class)->call('openPanel', 'move', $this->message->id, 0)
            ->set('choice', (string) $this->w['tracking']->id)->call('applyPanel');

        expect(Activity::query()->where('inbound_message_id', $this->message->id)->value('task_id'))->toBe($this->w['tracking']->id);
    });

    it('cannot act on another user\'s message', function () {
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $theirs = asUser($other->id, fn () => InboundMessage::factory()->create(['user_id' => $other->id]));

        Livewire::test(WorklogInput::class)->call('undo', $theirs->id)->assertNotFound();

        expect(InboundMessage::query()->count())->toBe(1);   // only this user's own message is visible at all
    });
});

it('is the dashboard home for a signed-in user, in their language, with their own data only', function () {
    $this->user->update(['default_language' => 'en']);
    InboundMessage::factory()->create(['user_id' => $this->user->id, 'text' => 'my own note', 'source' => 'dashboard', 'idempotency_key' => 'dashboard:x:1']);
    $other = User::factory()->create(['telegram_user_id' => 888002]);
    asUser($other->id, fn () => InboundMessage::factory()->create(['user_id' => $other->id, 'text' => 'somebody elses note']));

    $this->get('/admin')->assertOk()->assertSee('Log Work')->assertSee('my own note')->assertDontSee('somebody elses note');
});
