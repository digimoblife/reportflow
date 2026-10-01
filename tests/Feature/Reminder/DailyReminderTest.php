<?php

use App\Enums\ReminderState;
use App\Jobs\SendReminder;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ReminderInstance;
use App\Models\ReminderRule;
use App\Models\Task;
use App\Models\User;
use App\Services\Reminder\ReminderSchedule;
use App\Services\Reminder\ReminderSettings;
use App\Services\Telegram\ReminderCallback;
use App\Services\Telegram\TelegramApiException;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

// 2026-09-30 is a Wednesday. Asia/Jakarta is UTC+7, so the default 18:00 is 11:00 UTC.

beforeEach(function () {
    $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta', 'workdays' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'reminders_enabled' => true]);
    $this->w = worklogWorld($this->user);
    // worklogWorld makes a stranger's project too; only the users a test creates on purpose should receive reminders.
    User::query()->where('id', '!=', $this->user->id)->update(['reminders_enabled' => false]);
});

afterEach(fn () => Carbon::setTestNow());

function at(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
}

function tick(): void
{
    Artisan::call('reminders:dispatch');
    app(UserContext::class)->set(test()->user->id);
}

function reminderTexts(): array
{
    return array_column(fakeTelegram()->sent, 'text');
}

describe('when a reminder goes out', function () {
    it('is sent at the scheduled local time on a workday, with its three buttons, to the user\'s chat', function () {
        at('2026-09-30 10:59:00');
        tick();
        expect(fakeTelegram()->sent)->toBe([]);

        at('2026-09-30 11:00:00');
        tick();

        $sent = fakeTelegram()->sent;
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['chat_id'])->toBe(555001)
            ->and(isVariantOf($sent[0]['text'], 'reminder.daily'))->toBeTrue()
            ->and(collect($sent[0]['keyboard'])->flatten(1)->map(fn ($b) => ReminderCallback::parse($b['callback_data'])->action)->all())->toBe(['add', 'none', 'later'])
            ->and(collect($sent[0]['keyboard'])->flatten(1)->pluck('text')->all())->toBe(['➕ Tambah catatan', 'Tidak ada hari ini', '⏰ Ingatkan 1 jam lagi']);

        $instance = ReminderInstance::query()->sole();
        expect($instance->status)->toBe(ReminderState::Sent)->and($instance->reminder_date->format('Y-m-d'))->toBe('2026-09-30')
            ->and($instance->send_count)->toBe(1)->and($instance->telegram_message_id)->not->toBeNull();
    });

    it('is not sent on a day outside the workdays, unless the user added it', function () {
        at('2026-10-03 11:00:00');   // Saturday
        tick();
        expect(fakeTelegram()->sent)->toBe([]);

        $this->user->update(['workdays' => ['mon', 'sat']]);
        tick();
        expect(fakeTelegram()->sent)->toHaveCount(1);
    });

    it('follows the user\'s timezone, not the server\'s', function () {
        $far = registerTelegramUser(555002, ['timezone' => 'Pacific/Kiritimati', 'workdays' => ['mon', 'tue', 'wed', 'thu', 'fri']]);   // UTC+14: 18:00 = 04:00 UTC the same date

        at('2026-09-30 03:59:00');
        tick();
        expect(array_column(fakeTelegram()->sent, 'chat_id'))->not->toContain(555002);

        at('2026-09-30 04:00:00');
        tick();
        expect(array_column(fakeTelegram()->sent, 'chat_id'))->toBe([555002]);
    });

    it('uses the time the user configured', function () {
        app(ReminderSettings::class)->setTime('09:30');

        at('2026-09-30 02:29:00');
        tick();
        expect(fakeTelegram()->sent)->toBe([]);

        at('2026-09-30 02:30:00');
        tick();
        expect(fakeTelegram()->sent)->toHaveCount(1);
    });

    it('is sent once even when the scheduler runs many times', function () {
        at('2026-09-30 11:00:00');
        tick();
        tick();
        at('2026-09-30 11:01:00');
        tick();
        at('2026-09-30 15:00:00');
        tick();

        expect(fakeTelegram()->sent)->toHaveCount(1)->and(ReminderInstance::query()->count())->toBe(1);
    });

    it('is sent again on the next workday', function () {
        at('2026-09-30 11:00:00');
        tick();
        at('2026-10-01 11:00:00');
        tick();

        expect(fakeTelegram()->sent)->toHaveCount(2)->and(ReminderInstance::query()->count())->toBe(2);
    });
});

describe('when it must not go out', function () {
    it('skips the day when something was already logged today, and cancels the instance', function () {
        Activity::factory()->for($this->w['tracking'])->create(['activity_date' => '2026-09-30']);

        at('2026-09-30 11:00:00');
        tick();

        expect(fakeTelegram()->sent)->toBe([])
            ->and(ReminderInstance::query()->sole()->status)->toBe(ReminderState::Cancelled)
            ->and(ReminderInstance::query()->sole()->action_taken)->toBe('already_logged');
    });

    it('is not stopped by yesterday\'s or tomorrow\'s activity', function () {
        Activity::factory()->for($this->w['tracking'])->create(['activity_date' => '2026-09-29']);
        Activity::factory()->for($this->w['tracking'])->create(['activity_date' => '2026-10-01']);

        at('2026-09-30 11:00:00');
        tick();

        expect(fakeTelegram()->sent)->toHaveCount(1);
    });

    it('counts "today" in the user\'s timezone', function () {
        // 2026-09-30 23:30 UTC is already 2026-10-01 in Jakarta. An activity dated 09-30 does not count for 10-01.
        Activity::factory()->for($this->w['tracking'])->create(['activity_date' => '2026-09-30']);
        $this->user->update(['workdays' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']]);
        app(ReminderSettings::class)->setTime('06:30');   // 06:30 Jakarta = 23:30 UTC the day before

        at('2026-09-30 23:30:00');
        tick();

        expect(fakeTelegram()->sent)->toHaveCount(1)->and(ReminderInstance::query()->sole()->reminder_date->format('Y-m-d'))->toBe('2026-10-01');
    });

    it('is silent when reminders are off or the rule is disabled', function () {
        $this->user->update(['reminders_enabled' => false]);
        at('2026-09-30 11:00:00');
        tick();
        expect(fakeTelegram()->sent)->toBe([])->and(ReminderInstance::query()->count())->toBe(0);

        $this->user->update(['reminders_enabled' => true]);
        app(ReminderSettings::class)->daily()->update(['enabled' => false]);
        tick();
        expect(fakeTelegram()->sent)->toBe([])->and(ReminderInstance::query()->count())->toBe(0);
    });

    it('does not create a reminder when the scheduler was down past the window, and drops a stale instance', function () {
        at('2026-09-30 13:01:00');   // 20:01 local: 2 h and 1 min late
        tick();
        expect(fakeTelegram()->sent)->toBe([])->and(ReminderInstance::query()->count())->toBe(0);

        $rule = app(ReminderSettings::class)->daily();
        $instance = ReminderInstance::factory()->create(['reminder_rule_id' => $rule->id, 'reminder_date' => '2026-09-30', 'next_run_at' => '2026-09-30 11:00:00+00']);
        tick();
        expect($instance->fresh()->status)->toBe(ReminderState::Cancelled)->and($instance->fresh()->action_taken)->toBe('expired');
    });

    it('never sends more than three reminder messages in one local day', function () {
        $rule = app(ReminderSettings::class)->daily();
        ReminderInstance::factory()->create(['reminder_rule_id' => $rule->id, 'reminder_date' => '2026-09-29', 'status' => ReminderState::Sent, 'sent_at' => '2026-09-30 01:00:00+00', 'send_count' => 3, 'next_run_at' => '2026-09-29 11:00:00+00']);
        // The 3 sends above fall on local 2026-09-30 (08:00 Jakarta) although the instance was for the day before.

        at('2026-09-30 11:00:00');
        tick();

        expect(fakeTelegram()->sent)->toBe([])
            ->and(ReminderInstance::query()->where('reminder_date', '2026-09-30')->sole()->action_taken)->toBe('daily_limit');
    });

    it('does not touch another user\'s reminders or let their activity count', function () {
        $other = registerTelegramUser(555002, ['timezone' => 'Asia/Jakarta']);
        asUser($other->id, fn () => Activity::factory()->for(Task::factory()->for(Project::factory()->create(['user_id' => $other->id]))->create())->create(['activity_date' => '2026-09-30']));

        at('2026-09-30 11:00:00');
        tick();

        // The other user logged work, so only this user is reminded.
        expect(array_column(fakeTelegram()->sent, 'chat_id'))->toBe([555001]);
    });

    it('skips users who are not linked to Telegram', function () {
        $this->user->update(['telegram_user_id' => null]);
        at('2026-09-30 11:00:00');
        tick();

        expect(fakeTelegram()->sent)->toBe([]);
    });
});

describe('sending', function () {
    it('sends nothing twice when the job is delivered twice', function () {
        at('2026-09-30 11:00:00');
        tick();
        $id = ReminderInstance::query()->value('id');

        SendReminder::dispatchSync($id, $this->user->id);
        SendReminder::dispatchSync($id, $this->user->id);

        expect(fakeTelegram()->sent)->toHaveCount(1);
    });

    it('finishes a claimed send that failed before Telegram answered', function () {
        at('2026-09-30 11:00:00');
        fakeTelegram()->failNextSend(TelegramApiException::fromResponse('sendMessage', 502, 'Bad Gateway'));
        tick();

        $instance = ReminderInstance::query()->sole();
        expect($instance->status)->toBe(ReminderState::Sent)->and($instance->telegram_message_id)->toBeNull()->and($instance->send_count)->toBe(1);

        SendReminder::dispatchSync($instance->id, $this->user->id);   // the retry

        expect(fakeTelegram()->sent)->toHaveCount(1)->and($instance->fresh()->telegram_message_id)->not->toBeNull()->and($instance->fresh()->send_count)->toBe(1);
    });

    it('cancels (and does not retry) when Telegram refuses permanently', function () {
        at('2026-09-30 11:00:00');
        fakeTelegram()->failNextSend(TelegramApiException::fromResponse('sendMessage', 403, 'Forbidden: bot was blocked by the user'));
        tick();

        expect(ReminderInstance::query()->sole()->status)->toBe(ReminderState::Cancelled)->and(ReminderInstance::query()->sole()->action_taken)->toBe('undeliverable');
    });

    it('asks the rules again right before sending: work logged meanwhile cancels it', function () {
        at('2026-09-30 11:00:00');
        $rule = app(ReminderSettings::class)->daily();
        $instance = ReminderInstance::factory()->create(['reminder_rule_id' => $rule->id, 'reminder_date' => '2026-09-30', 'next_run_at' => '2026-09-30 11:00:00+00']);
        Activity::factory()->for($this->w['tracking'])->create(['activity_date' => '2026-09-30']);

        SendReminder::dispatchSync($instance->id, $this->user->id);

        expect(fakeTelegram()->sent)->toBe([])->and($instance->fresh()->action_taken)->toBe('already_logged');
    });
});

describe('time helpers', function () {
    it('parses human time input strictly', function (string $input, ?string $expected) {
        expect(ReminderSchedule::parseTime($input))->toBe($expected);
    })->with([
        ['18:00', '18:00'], ['9:30', '09:30'], ['09.05', '09:05'], [' 7:00 ', '07:00'],
        ['24:00', null], ['18:60', null], ['18', null], ['6pm', null], ['18:5', null], ['', null], ['aa:bb', null],
    ]);

    it('creates the default rule once', function () {
        app(ReminderSettings::class)->daily();
        app(ReminderSettings::class)->daily();

        expect(ReminderRule::query()->count())->toBe(1)->and(app(ReminderSettings::class)->time())->toBe('18:00');
    });

    it('cancels what is waiting when reminders are switched off', function () {
        $rule = app(ReminderSettings::class)->daily();
        $waiting = ReminderInstance::factory()->create(['reminder_rule_id' => $rule->id, 'reminder_date' => '2026-09-30', 'status' => ReminderState::Snoozed, 'snoozed_until' => now()->addHour()]);

        app(ReminderSettings::class)->setEnabled($this->user, false);

        expect($waiting->fresh()->status)->toBe(ReminderState::Cancelled)->and($this->user->fresh()->reminders_enabled)->toBeFalse();
    });
});
