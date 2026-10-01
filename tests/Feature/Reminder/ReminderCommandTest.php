<?php

use App\Enums\ReminderState;
use App\Models\ReminderInstance;
use App\Services\Reminder\ReminderSettings;

beforeEach(function () {
    $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta', 'workdays' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'reminders_enabled' => true]);
});

function say(string $text, int $messageId = 10): string
{
    send($text, $messageId);

    return collect(fakeTelegram()->sent)->last()['text'];
}

it('shows the status: state, time and workdays', function (string $command) {
    $text = say($command);

    expect(isVariantOf($text, 'reminder.status', 'id', ['state' => 'AKTIF', 'time' => '18:00', 'days' => 'Sen, Sel, Rab, Kam, Jum']))->toBeTrue();
})->with(['/reminder', '/reminder status', '/reminder daily']);

it('switches reminders off and on, and cancels what was waiting', function () {
    $rule = asUser($this->user->id, fn () => app(ReminderSettings::class)->daily());
    $waiting = asUser($this->user->id, fn () => ReminderInstance::factory()->create(['reminder_rule_id' => $rule->id, 'reminder_date' => '2026-09-30', 'status' => ReminderState::Snoozed, 'snoozed_until' => now()->addHour()]));

    expect(isVariantOf(say('/reminder off'), 'reminder.off_done'))->toBeTrue()
        ->and($this->user->fresh()->reminders_enabled)->toBeFalse()
        ->and(asSystem(fn () => $waiting->fresh()->status))->toBe(ReminderState::Cancelled)
        ->and(isVariantOf(say('/reminder', 11), 'reminder.status', 'id', ['state' => 'MATI', 'time' => '18:00', 'days' => 'Sen, Sel, Rab, Kam, Jum']))->toBeTrue();

    expect(isVariantOf(say('/reminder on', 12), 'reminder.on_done'))->toBeTrue()->and($this->user->fresh()->reminders_enabled)->toBeTrue();
});

it('sets the daily time, accepting common forms and refusing nonsense', function () {
    expect(isVariantOf(say('/reminder daily 17:30'), 'reminder.daily_set', 'id', ['time' => '17:30']))->toBeTrue()
        ->and(asUser($this->user->id, fn () => app(ReminderSettings::class)->time()))->toBe('17:30');

    expect(isVariantOf(say('/reminder daily 9.05', 11), 'reminder.daily_set', 'id', ['time' => '09:05']))->toBeTrue();

    foreach (['/reminder daily 25:00', '/reminder daily soon', '/reminder daily 18'] as $i => $bad) {
        expect(isVariantOf(say($bad, 20 + $i), 'reminder.daily_invalid'))->toBeTrue();
    }

    expect(asUser($this->user->id, fn () => app(ReminderSettings::class)->time()))->toBe('09:05');   // unchanged by the bad ones
});

it('says the monthly reminder is not there yet, and shows usage for anything else', function () {
    expect(isVariantOf(say('/reminder monthly'), 'reminder.monthly_unavailable'))->toBeTrue()
        ->and(isVariantOf(say('/reminder whatever', 11), 'reminder.usage'))->toBeTrue();
});

it('speaks English to an English user', function () {
    $this->user->update(['default_language' => 'en']);

    expect(isVariantOf(say('/reminder'), 'reminder.status', 'en', ['state' => 'ON', 'time' => '18:00', 'days' => 'Mon, Tue, Wed, Thu, Fri']))->toBeTrue();
});

it('keeps each user\'s settings apart', function () {
    $other = registerTelegramUser(555002, ['timezone' => 'Asia/Jakarta']);
    send('/reminder daily 07:00', 10);

    expect(asUser($other->id, fn () => app(ReminderSettings::class)->time()))->toBe('18:00')
        ->and(asUser($this->user->id, fn () => app(ReminderSettings::class)->time()))->toBe('07:00');
});
