<?php

use App\Enums\ReminderState;
use App\Enums\ReminderType;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ReminderInstance;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiRequest;
use App\Services\Reminder\ReminderSettings;
use App\Services\Telegram\ReminderCallback;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

// Asia/Jakarta is UTC+7: the default monthly time 09:00 is 02:00 UTC. 30 September 2026 is a Wednesday, 31 October a Saturday.

beforeEach(function () {
    $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta', 'workdays' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'reminders_enabled' => true]);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal', 'slug' => 'harbor-portal']);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Shipment Tracking API', 'status' => TaskStatus::InProgress, 'started_at' => '2026-08-10 02:00:00+00']);
    Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-03', 'summary' => 'Webhook receiver written']);
    Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-15', 'summary' => 'Retry logic added']);
    User::query()->where('id', '!=', $this->user->id)->update(['reminders_enabled' => false]);
    app(ReminderSettings::class)->daily()->update(['enabled' => false]);   // these tests are about the monthly reminder
});

afterEach(fn () => Carbon::setTestNow());

function monthEnd(string $utc): void
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    Artisan::call('reminders:dispatch');
    app(UserContext::class)->set(test()->user->id);
}

function monthlyInstance(): ?ReminderInstance
{
    return ReminderInstance::query()->whereHas('rule', fn ($q) => $q->where('type', ReminderType::MonthlyReport))->latest('id')->first();
}

describe('when it goes out', function () {
    it('is sent on the last day of the month at the monthly time, with the month in context', function () {
        monthEnd('2026-09-30 01:59:00');
        expect(fakeTelegram()->sent)->toBe([]);

        monthEnd('2026-09-30 02:00:00');

        $sent = fakeTelegram()->sent;
        $buttons = collect($sent[0]['keyboard'])->flatten(1);
        expect($sent)->toHaveCount(1)->and($sent[0]['chat_id'])->toBe(555001)
            ->and($sent[0]['text'])->toContain('September 2026')->toContain('📁 Harbor Portal')->toContain('Aktivitas: 2')->toContain('Task berjalan: 1')->toContain('Lintas bulan: 1')
            ->and($buttons->map(fn ($b) => ReminderCallback::parse($b['callback_data'])->action)->all())->toBe(['gen', 'rev', 'later'])
            ->and(monthlyInstance()->reminder_date->format('Y-m-d'))->toBe('2026-09-30')->and(monthlyInstance()->status)->toBe(ReminderState::Sent);
    });

    it('is not sent on other days of the month', function (string $utc) {
        monthEnd($utc);

        expect(fakeTelegram()->sent)->toBe([]);
    })->with(['2026-09-29 02:00:00', '2026-09-15 02:00:00', '2026-10-01 02:00:00']);

    it('knows the length of every month, leap years included', function (string $utc, bool $sends) {
        Activity::factory()->for($this->task)->create(['activity_date' => substr($utc, 0, 8).'10']);
        monthEnd($utc);

        expect(fakeTelegram()->sent !== [])->toBe($sends);
    })->with([
        'february 2027' => ['2027-02-28 02:00:00', true],
        'february 2027, the 27th' => ['2027-02-27 02:00:00', false],
        'february 2028 (leap)' => ['2028-02-29 02:00:00', true],
        'february 2028, the 28th' => ['2028-02-28 02:00:00', false],
        'april' => ['2027-04-30 02:00:00', true],
        'december' => ['2026-12-31 02:00:00', true],
    ]);

    it('does not depend on workdays: a Saturday month-end is sent', function () {
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-10-20']);

        monthEnd('2026-10-31 02:00:00');

        expect(fakeTelegram()->sent)->toHaveCount(1)->and(fakeTelegram()->sent[0]['text'])->toContain('Oktober 2026');
    });

    it('follows the user\'s calendar, not the server\'s', function () {
        $far = registerTelegramUser(555002, ['timezone' => 'Pacific/Kiritimati', 'reminders_enabled' => true]);   // UTC+14
        $projectFar = asUser($far->id, fn () => Project::factory()->create(['user_id' => $far->id]));
        asUser($far->id, fn () => Activity::factory()->for(Task::factory()->for($projectFar)->create())->create(['activity_date' => '2026-09-10']));
        asUser($far->id, fn () => app(ReminderSettings::class)->daily()->update(['enabled' => false]));

        monthEnd('2026-09-29 18:59:00');   // 08:59 on 30 September there
        expect(array_column(fakeTelegram()->sent, 'chat_id'))->not->toContain(555002);

        monthEnd('2026-09-29 19:00:00');   // 09:00 on 30 September there; still the 29th in UTC
        expect(array_column(fakeTelegram()->sent, 'chat_id'))->toBe([555002]);
    });

    it('is sent once however often the scheduler runs, and not after the two-hour window', function () {
        monthEnd('2026-09-30 02:00:00');
        monthEnd('2026-09-30 02:01:00');
        monthEnd('2026-09-30 05:00:00');
        expect(fakeTelegram()->sent)->toHaveCount(1)->and(ReminderInstance::query()->count())->toBe(1);

        ReminderInstance::query()->delete();
        fakeTelegram()->sent = [];
        monthEnd('2026-09-30 04:01:00');   // 11:01 local: the scheduler was down for two hours
        expect(fakeTelegram()->sent)->toBe([]);
    });

    it('can use another time, and be switched off', function () {
        app(ReminderSettings::class)->setMonthlyTime('18:00');
        monthEnd('2026-09-30 02:00:00');
        expect(fakeTelegram()->sent)->toBe([]);
        monthEnd('2026-09-30 11:00:00');
        expect(fakeTelegram()->sent)->toHaveCount(1);

        fakeTelegram()->sent = [];
        ReminderInstance::query()->delete();
        app(ReminderSettings::class)->setMonthlyEnabled(false);
        monthEnd('2026-09-30 11:05:00');
        expect(fakeTelegram()->sent)->toBe([]);
    });
});

describe('when it must not go out', function () {
    it('stays silent for a month without activity', function () {
        Activity::query()->delete();

        monthEnd('2026-09-30 02:00:00');

        expect(fakeTelegram()->sent)->toBe([])->and(monthlyInstance()->status)->toBe(ReminderState::Cancelled)->and(monthlyInstance()->action_taken)->toBe('no_activity');
    });

    it('stays silent once every project with activity has an approved report for the month', function () {
        Report::factory()->create(['project_id' => $this->project->id, 'status' => ReportStatus::Approved, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']);

        monthEnd('2026-09-30 02:00:00');

        expect(fakeTelegram()->sent)->toBe([])->and(monthlyInstance()->action_taken)->toBe('report_exists');
    });

    it('still reminds when only some of the projects are reported', function () {
        $kedai = Project::factory()->create(['name' => 'Kedai App', 'slug' => 'kedai-app']);
        Activity::factory()->for(Task::factory()->for($kedai)->create())->create(['activity_date' => '2026-09-12']);
        Report::factory()->create(['project_id' => $this->project->id, 'status' => ReportStatus::Approved, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']);

        monthEnd('2026-09-30 02:00:00');

        expect(fakeTelegram()->sent)->toHaveCount(1)->and(fakeTelegram()->sent[0]['text'])->toContain('Harbor Portal')->toContain('Kedai App');
    });

    it('does not count a draft or in-review report as reported', function () {
        Report::factory()->create(['project_id' => $this->project->id, 'status' => ReportStatus::InReview, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']);

        monthEnd('2026-09-30 02:00:00');

        expect(fakeTelegram()->sent)->toHaveCount(1);
    });

    it('shares the three-messages-a-day limit with the daily reminder', function () {
        $rule = app(ReminderSettings::class)->daily();
        ReminderInstance::factory()->create(['reminder_rule_id' => $rule->id, 'reminder_date' => '2026-09-29', 'status' => ReminderState::Sent, 'sent_at' => '2026-09-30 00:30:00+00', 'send_count' => 3, 'next_run_at' => '2026-09-29 11:00:00+00']);

        monthEnd('2026-09-30 02:00:00');

        expect(fakeTelegram()->sent)->toBe([])->and(monthlyInstance()->action_taken)->toBe('daily_limit');
    });

    it('goes out next to the daily reminder on a workday month-end', function () {
        app(ReminderSettings::class)->daily()->update(['enabled' => true]);
        Activity::query()->delete();
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-10']);

        monthEnd('2026-09-30 02:00:00');
        monthEnd('2026-09-30 11:00:00');

        expect(fakeTelegram()->sent)->toHaveCount(2);
    });
});

describe('the buttons', function () {
    beforeEach(function () {
        monthEnd('2026-09-30 02:00:00');
        $this->instance = monthlyInstance();
    });

    function pressMonthly(string $action, string $callbackId = 'cb-m'): void
    {
        $instance = monthlyInstance();
        postTelegram(callbackPayload((new ReminderCallback($instance->id, $action))->encode(), 555001, $callbackId, (int) $instance->telegram_message_id))->assertOk();
    }

    it('"Generate Report" starts the report of that month and asks nothing more when one project has activity', function () {
        fakeAi()->using(function (AiRequest $r) {
            $p = json_decode($r->user, true);

            return json_encode(['markdown' => 'Work centred on {{task:'.$p['tasks'][0]['id'].'}}.', 'used_task_ids' => [$p['tasks'][0]['id']]]);
        });

        pressMonthly('gen');

        $report = Report::query()->sole();
        expect($this->instance->fresh()->status)->toBe(ReminderState::Acknowledged)->and($this->instance->fresh()->action_taken)->toBe('gen')
            ->and($report->period_start->format('Y-m-d'))->toBe('2026-09-01')->and($report->period_end->format('Y-m-d'))->toBe('2026-09-30')
            ->and($report->status)->toBe(ReportStatus::InReview)
            ->and(isVariantOf(collect(fakeTelegram()->edits)->last()['text'], 'reminder.monthly_started', 'id'))->toBeTrue();
    });

    it('"Review Activities" shows the tasks in progress', function () {
        pressMonthly('rev');

        expect($this->instance->fresh()->action_taken)->toBe('rev')->and(collect(fakeTelegram()->sent)->last()['text'])->toContain('Shipment Tracking API');
    });

    it('"Later" asks again a day later, and gives up after the deadline', function () {
        pressMonthly('later');

        expect($this->instance->fresh()->status)->toBe(ReminderState::Snoozed)->and($this->instance->fresh()->snoozed_until->toIso8601String())->toBe('2026-10-01T02:00:00+00:00');

        monthEnd('2026-10-01 01:59:00');
        expect(fakeTelegram()->sent)->toHaveCount(1);

        monthEnd('2026-10-01 02:00:00');
        expect(fakeTelegram()->sent)->toHaveCount(2)->and($this->instance->fresh()->send_count)->toBe(2);

        // a snooze that would fire after the third day is dropped
        pressMonthly('later', 'cb-m2');
        monthEnd('2026-10-03 17:00:00');
        expect(fakeTelegram()->sent)->toHaveCount(2)->and($this->instance->fresh()->action_taken)->toBe('expired');
    });

    it('answers only the first press', function () {
        pressMonthly('rev');
        pressMonthly('gen', 'cb-m3');

        expect($this->instance->fresh()->action_taken)->toBe('rev')->and(Report::query()->count())->toBe(0)
            ->and(isVariantOf(collect(fakeTelegram()->answers)->last()['text'], 'reminder.answered', 'id'))->toBeTrue();
    });
});
