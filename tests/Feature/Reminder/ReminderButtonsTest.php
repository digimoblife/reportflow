<?php

use App\Enums\ReminderState;
use App\Models\Activity;
use App\Models\ReminderInstance;
use App\Models\User;
use App\Services\Reminder\ReminderSettings;
use App\Services\Telegram\ReminderCallback;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta', 'workdays' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'reminders_enabled' => true]);
    $this->w = worklogWorld($this->user);
    User::query()->where('id', '!=', $this->user->id)->update(['reminders_enabled' => false]);
});

afterEach(fn () => Carbon::setTestNow());

function remindAt(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    Artisan::call('reminders:dispatch');
    app(UserContext::class)->set(test()->user->id);
}

function pressReminder(string $action, ?ReminderInstance $instance = null, string $callbackId = 'cb-1', ?int $bubble = null, int $from = 555001): void
{
    $instance ??= ReminderInstance::query()->latest('id')->firstOrFail();
    postTelegram(callbackPayload((new ReminderCallback($instance->id, $action))->encode(), $from, $callbackId, $bubble ?? (int) $instance->telegram_message_id))->assertOk();
}

function lastToast(): ?string
{
    return collect(fakeTelegram()->answers)->last()['text'] ?? null;
}

function lastEdit(): array
{
    return collect(fakeTelegram()->edits)->last();
}

describe('payload', function () {
    it('round-trips and rejects anything else', function () {
        expect(ReminderCallback::parse('r:12:later'))->toEqual(new ReminderCallback(12, 'later'))
            ->and((new ReminderCallback(7, 'add'))->encode())->toBe('r:7:add');

        foreach (['', 'r:', 'r:0:add', 'r:1:ADD', 'r:1:evil', 'r:x:add', "r:1:add\n", 'a:1:add', 'r:1:add:2', str_repeat('r', 70)] as $bad) {
            expect(ReminderCallback::parse($bad))->toBeNull();
        }
    });
});

describe('buttons', function () {
    beforeEach(fn () => remindAt('2026-09-30 11:00:00'));

    it('"Add note" acknowledges the reminder and invites the note', function () {
        pressReminder('add');

        $instance = ReminderInstance::query()->sole();
        expect($instance->status)->toBe(ReminderState::Acknowledged)->and($instance->action_taken)->toBe('add')
            ->and(isVariantOf(lastEdit()['text'], 'reminder.add_prompt'))->toBeTrue()->and(lastEdit()['keyboard'])->toBe([]);
    });

    it('"Nothing today" closes the day: no further reminder, even later', function () {
        pressReminder('none');

        expect(ReminderInstance::query()->sole()->status)->toBe(ReminderState::Dismissed)
            ->and(isVariantOf(lastEdit()['text'], 'reminder.none_done'))->toBeTrue();

        remindAt('2026-09-30 11:01:00');
        remindAt('2026-09-30 14:00:00');
        expect(fakeTelegram()->sent)->toHaveCount(1);
    });

    it('"tomorrow" on a daily reminder is the same as nothing today', function () {
        pressReminder('tomorrow');

        expect(ReminderInstance::query()->sole()->status)->toBe(ReminderState::Dismissed)->and(ReminderInstance::query()->sole()->action_taken)->toBe('tomorrow');
    });

    it('"Remind me in an hour" snoozes, then sends again exactly an hour later', function () {
        pressReminder('later');

        $instance = ReminderInstance::query()->sole();
        expect($instance->status)->toBe(ReminderState::Snoozed)->and($instance->snooze_count)->toBe(1)
            ->and($instance->snoozed_until->toIso8601String())->toBe('2026-09-30T12:00:00+00:00')
            ->and(isVariantOf(lastEdit()['text'], 'reminder.snoozed'))->toBeTrue();

        remindAt('2026-09-30 11:59:00');
        expect(fakeTelegram()->sent)->toHaveCount(1);

        remindAt('2026-09-30 12:00:00');
        expect(fakeTelegram()->sent)->toHaveCount(2)->and($instance->fresh()->status)->toBe(ReminderState::Sent)
            ->and($instance->fresh()->send_count)->toBe(2)->and($instance->fresh()->telegram_message_id)->toBe(fakeTelegram()->sent[1]['message_id']);
    });

    it('a snoozed reminder is dropped when work was logged in the meantime', function () {
        pressReminder('later');
        Activity::factory()->for($this->w['tracking'])->create(['activity_date' => '2026-09-30']);

        remindAt('2026-09-30 12:00:00');

        expect(fakeTelegram()->sent)->toHaveCount(1)->and(ReminderInstance::query()->sole()->action_taken)->toBe('already_logged');
    });

    it('stops after three messages in a day even though the user keeps snoozing', function () {
        pressReminder('later', callbackId: 'a');
        remindAt('2026-09-30 12:00:00');
        pressReminder('later', callbackId: 'b');
        remindAt('2026-09-30 13:00:00');
        pressReminder('later', callbackId: 'c');
        expect(ReminderInstance::query()->sole()->snooze_count)->toBe(3);

        remindAt('2026-09-30 14:00:00');   // the cap of 3 messages a day is reached here as well
        expect(fakeTelegram()->sent)->toHaveCount(3);
    });

    it('refuses a fourth snooze and closes the reminder', function () {
        $instance = ReminderInstance::query()->sole();
        $instance->update(['snooze_count' => 3]);

        pressReminder('later');

        expect($instance->fresh()->status)->toBe(ReminderState::Dismissed)->and($instance->fresh()->action_taken)->toBe('snooze_limit')
            ->and(isVariantOf(lastEdit()['text'], 'reminder.snooze_limit'))->toBeTrue();
    });

    it('does not snooze across midnight: the next workday covers it', function () {
        ReminderInstance::query()->delete();
        fakeTelegram()->sent = [];
        app(ReminderSettings::class)->setTime('23:10');
        remindAt('2026-09-30 16:10:00');   // 23:10 Jakarta
        Carbon::setTestNow(Carbon::parse('2026-09-30 16:30:00', 'UTC'));   // 23:30 local; +1 h is past midnight

        pressReminder('later');

        expect(ReminderInstance::query()->sole()->status)->toBe(ReminderState::Dismissed)->and(ReminderInstance::query()->sole()->action_taken)->toBe('snooze_past_day');
    });

    it('only the first press counts; a second tap or an old bubble gets "already answered"', function () {
        $instance = ReminderInstance::query()->sole();
        $firstBubble = (int) $instance->telegram_message_id;

        pressReminder('none', callbackId: 'x1');
        pressReminder('later', callbackId: 'x2');
        expect($instance->fresh()->status)->toBe(ReminderState::Dismissed)->and(isVariantOf(lastToast(), 'reminder.answered'))->toBeTrue();
    });

    it('ignores the old bubble once a snooze sent a new one', function () {
        $first = ReminderInstance::query()->sole();
        $oldBubble = (int) $first->telegram_message_id;
        pressReminder('later', callbackId: 'y1');
        remindAt('2026-09-30 12:00:00');

        pressReminder('none', callbackId: 'y2', bubble: $oldBubble);

        expect($first->fresh()->status)->toBe(ReminderState::Sent);   // still waiting for an answer on the new bubble
        pressReminder('none', callbackId: 'y3');   // the new bubble works
        expect($first->fresh()->status)->toBe(ReminderState::Dismissed);
    });

    it('cannot be answered by another user', function () {
        registerTelegramUser(555002, ['timezone' => 'Asia/Jakarta', 'reminders_enabled' => false]);

        pressReminder('none', from: 555002, callbackId: 'z1');

        expect(ReminderInstance::query()->sole()->status)->toBe(ReminderState::Sent)->and(isVariantOf(lastToast(), 'callback.expired'))->toBeTrue();
    });
});
