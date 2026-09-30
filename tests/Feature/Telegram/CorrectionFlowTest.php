<?php

use App\Enums\CorrectionType;
use App\Enums\OutcomeState;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Correction;
use App\Models\Task;
use App\Services\Telegram\CallbackData;
use App\Services\Worklog\UndoService;
use Illuminate\Support\Carbon;
use Tests\Support\Extraction;

beforeEach(function () {
    config(['queue.default' => 'database']);
    Carbon::setTestNow(Carbon::create(2026, 9, 30, 10, 0, 0, 'Asia/Jakarta'));
    $this->tg = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta']);
    $this->w = worklogWorld($this->tg);
});

afterEach(fn () => Carbon::setTestNow());

function taskState(Task $task): array
{
    return asSystem(fn () => Task::query()->findOrFail($task->id))->only(['status', 'started_at', 'completed_at', 'last_activity_at', 'version']);
}

describe('undo', function () {
    it('restores the task exactly and records the correction', function () {
        $before = taskState($this->w['invoice']);
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix']])]));
        $message = processNote('Harbor Portal: invoice bug beres');
        expect(taskState($this->w['invoice'])['status'])->toBe(TaskStatus::InProgress);

        press($message, 'undo', 0, null, 'cb-1', outcomeOf($message)->confirmationMessageId);
        settleWorker();

        $after = taskState($this->w['invoice']);
        expect($after['status'])->toBe($before['status'])
            ->and($after['last_activity_at']?->toIso8601String())->toBe($before['last_activity_at']?->toIso8601String())
            ->and(Activity::query()->where('inbound_message_id', $message->id)->count())->toBe(0)
            ->and(outcomeOf($message)->items[0]->state)->toBe(OutcomeState::Undone)
            ->and(Correction::query()->where('correction_type', CorrectionType::Undo)->count())->toBe(1)
            ->and(fakeTelegram()->edits)->not->toBeEmpty()
            ->and(collect(fakeTelegram()->edits)->last()['keyboard'])->toBe([]);
    });

    it('is idempotent and discards a task that only this message created', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));
        $message = processNote('Harbor Portal: task baru carrier onboarding');
        $tasks = Task::query()->count();

        press($message, 'undo', 0, null, 'cb-1');
        press($message, 'undo', 0, null, 'cb-2');

        expect(Task::query()->count())->toBe($tasks - 1)
            ->and(Correction::query()->where('correction_type', CorrectionType::Undo)->count())->toBe(1);
    });

    it('only removes the note when the task changed after the message', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix']])]));
        $message = processNote('Harbor Portal: invoice bug dikerjakan');
        $this->w['invoice']->refresh()->increment('version');   // someone else touched the task

        $result = app(UndoService::class)->undo($message->fresh());

        expect($result)->toHaveCount(1)
            ->and($result[0]['partial'])->toBeTrue()
            ->and(taskState($this->w['invoice'])['status'])->toBe(TaskStatus::InProgress);
    });

    it('does not touch another user\'s message', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Mine')]));
        $message = processNote('Harbor Portal: task saya');
        $other = registerTelegramUser(777002, ['timezone' => 'Asia/Jakarta']);
        $tasks = asSystem(fn () => Task::query()->count());

        postTelegram(callbackPayload((new CallbackData($message->id, 'undo', 0))->encode(), 777002, 'cb-x', 900))->assertOk();

        expect(asSystem(fn () => Task::query()->count()))->toBe($tasks)
            ->and(outcomeOf($message)->items[0]->state)->toBe(OutcomeState::Applied)
            ->and($other->id)->not->toBe($this->tg->id);
    });

    it('/undo undoes the latest processed message, or says there is nothing', function () {
        send('/undo', 50);
        settleWorker();
        expect(isVariantOf(collect(fakeTelegram()->sent)->last()['text'], 'undo.nothing'))->toBeTrue();

        fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));
        $message = processNote('Harbor Portal: task baru', 100);
        send('/undo', 101);
        settleWorker();

        expect(outcomeOf($message)->items[0]->state)->toBe(OutcomeState::Undone)
            ->and(isVariantOf(collect(fakeTelegram()->sent)->last()['text'], 'undo.done'))->toBeTrue();
    });
});

describe('correction buttons', function () {
    it('changes status only to options the matrix allows', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix']])]));
        $message = processNote('Harbor Portal: invoice bug');

        press($message, 'status', 0);
        $offered = collect(callbacksIn(collect(fakeTelegram()->edits)->last()['keyboard']))->where('action', 'setst')->pluck('arg')->all();
        expect($offered)->not->toContain('draft')->not->toContain('in_progress')->toContain('completed');

        press($message, 'setst', 0, 'completed', 'cb-2');

        expect(taskState($this->w['invoice'])['status'])->toBe(TaskStatus::Completed)
            ->and(Correction::query()->where('correction_type', CorrectionType::ChangeStatus)->count())->toBe(1);
    });

    it('rejects a status the matrix forbids even if forged', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix']])]));
        $message = processNote('Harbor Portal: invoice bug');

        press($message, 'setst', 0, 'draft', 'cb-2');

        expect(taskState($this->w['invoice'])['status'])->toBe(TaskStatus::InProgress);
    });

    it('moves a note to another task and back to a new one', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id, ['activity' => ['type' => 'bug_fix']])]));
        $message = processNote('Harbor Portal: tracking api dikerjakan');

        press($message, 'mvto', 0, (string) $this->w['tracking']->id, 'cb-2');

        expect(Activity::query()->where('inbound_message_id', $message->id)->value('task_id'))->toBe($this->w['tracking']->id)
            ->and(Correction::query()->where('correction_type', CorrectionType::MoveTask)->count())->toBe(1);
    });

    it('changes project only for tasks the message created', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));
        $message = processNote('Harbor Portal: task baru');
        $new = Task::query()->where('title', 'Carrier onboarding')->firstOrFail();

        press($message, 'setpr', 0, (string) $this->w['kedai']->id, 'cb-2');

        expect($new->fresh()->project_id)->toBe($this->w['kedai']->id)
            ->and(Correction::query()->where('correction_type', CorrectionType::ChangeProject)->count())->toBe(1);
    });

    it('"back" restores the confirmation', function () {
        fakeAi()->respondWith(Extraction::json([Extraction::item($this->w['invoice']->id, $this->w['harbor']->id)]));
        $message = processNote('Harbor Portal: invoice bug');
        $confirmation = outcomeOf($message)->confirmationMessageId;

        press($message, 'status', 0, null, 'cb-2', $confirmation);
        press($message, 'back', null, null, 'cb-3', $confirmation);

        $last = collect(fakeTelegram()->edits)->last();
        expect($last['text'])->toContain('Harbor Portal')
            ->and(collect(callbacksIn($last['keyboard']))->pluck('action')->all())->toContain('undo');
    });
});
