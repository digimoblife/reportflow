<?php

use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Jobs\GenerateReport;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Report;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiRequest;
use App\Services\Report\ReportWorkflow;
use App\Services\Telegram\ReportCallback;
use App\Services\Telegram\ReportCommands;
use App\Services\Telegram\ReportStartCallback;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 1, 9, 0, 0, 'Asia/Jakarta'));
    $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta', 'default_language' => 'id']);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal', 'slug' => 'harbor-portal', 'default_language' => Language::English]);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Shipment Tracking API', 'status' => TaskStatus::InProgress, 'started_at' => '2026-09-02 02:00:00+00']);
    Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-03', 'summary' => 'Webhook receiver written']);

    fakeAi()->using(function (AiRequest $r) {
        $p = json_decode($r->user, true);

        return json_encode(['markdown' => 'Work centred on {{task:'.$p['tasks'][0]['id'].'}}.', 'used_task_ids' => [$p['tasks'][0]['id']]]);
    });
});

afterEach(fn () => Carbon::setTestNow());

function texts(): array
{
    return array_column(fakeTelegram()->sent, 'text');
}

describe('/report', function () {
    it('makes last month\'s report for the only project with activity, and sends the review', function () {
        send('/report', 10);

        $report = Report::query()->sole();
        expect($report->type->value)->toBe('monthly')->and($report->period_start->format('Y-m-d'))->toBe('2026-09-01')->and($report->period_end->format('Y-m-d'))->toBe('2026-09-30')
            ->and($report->language)->toBe(Language::English)   // the project's report language, not the bot language
            ->and($report->status)->toBe(ReportStatus::InReview)->and($report->currentVersion->source_channel->value)->toBe('telegram')
            ->and(collect(texts())->contains(fn ($t) => isVariantOf($t, 'report.generating', 'id')))->toBeTrue()   // (the queue runs inline in tests, so the review may arrive first)
            ->and(collect(texts())->contains(fn ($t) => str_contains($t, 'Harbor Portal Monthly Report — September 2026')))->toBeTrue()
            ->and(fakeTelegram()->documents)->toHaveCount(1);
    });

    it('takes the month being closed from day 25, the last month before that', function (string $now, string $expected) {
        $user = test()->user;

        expect(app(ReportCommands::class)->month($user, null, CarbonImmutable::parse($now, 'UTC')))->toBe($expected);
    })->with([
        ['2026-10-01 02:00:00', '2026-09'],      // 09:00 on the 1st in Jakarta
        ['2026-10-24 16:59:00', '2026-09'],      // 23:59 on the 24th in Jakarta
        ['2026-10-24 17:00:00', '2026-10'],      // midnight of the 25th in Jakarta
        ['2026-10-31 02:00:00', '2026-10'],
        ['2027-01-03 02:00:00', '2026-12'],      // across the year
    ]);

    it('accepts an explicit month and refuses anything else', function () {
        send('/report 2026-08', 10);
        expect(isVariantOf(texts()[0], 'report.no_activity', 'id', ['month' => 'Agustus 2026']))->toBeTrue()->and(Report::query()->count())->toBe(0);

        send('/report foo', 11);
        expect(isVariantOf(texts()[1], 'report.start_usage', 'id'))->toBeTrue();

        send('/report 2026-13', 12);
        expect(isVariantOf(texts()[2], 'report.start_usage', 'id'))->toBeTrue();
    });

    it('lets the person pick when several projects have activity, and leaves out the ones without', function () {
        $kedai = Project::factory()->create(['name' => 'Kedai App', 'slug' => 'kedai-app']);
        $quiet = Project::factory()->create(['name' => 'Quiet Project', 'slug' => 'quiet']);
        Activity::factory()->for(Task::factory()->for($kedai)->create(['title' => 'Menu Sync']))->create(['activity_date' => '2026-09-10']);

        send('/report', 10);

        $buttons = collect(collect(fakeTelegram()->sent)->last()['keyboard'])->flatten(1);
        expect($buttons->pluck('text')->all())->toBe(['📁 Harbor Portal', '📁 Kedai App'])->and(Report::query()->count())->toBe(0);

        postTelegram(callbackPayload($buttons[1]['callback_data'], 555001, 'cb-p', 800))->assertOk();

        expect(Report::query()->sole()->project_id)->toBe($kedai->id)->and($quiet->id)->not->toBe($kedai->id);
    });

    it('ignores a project button for a project that is not the user\'s', function () {
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $theirs = asUser($other->id, fn () => Project::factory()->create(['user_id' => $other->id]));

        postTelegram(callbackPayload((new ReportStartCallback($theirs->id, '2026-09'))->encode(), 555001, 'cb-x'))->assertOk();

        expect(Report::query()->count())->toBe(0);
    });
});

describe('entries that are still being processed', function () {
    beforeEach(function () {
        InboundMessage::factory()->count(2)->create(['user_id' => $this->user->id, 'status' => InboundMessageStatus::Processing]);
    });

    it('asks whether to wait, and generating without them goes ahead', function () {
        send('/report', 10);

        $ask = collect(fakeTelegram()->sent)->last();
        $actions = collect($ask['keyboard'])->flatten(1)->map(fn ($b) => ReportCallback::parse($b['callback_data'])->action)->all();
        expect($actions)->toBe(['wt', 'sk'])->and(Report::query()->sole()->status)->toBe(ReportStatus::Draft)->and($ask['text'])->toMatch('/2 catatan/');

        postTelegram(callbackPayload(collect($ask['keyboard'])->flatten(1)[1]['callback_data'], 555001, 'cb-s', 801))->assertOk();

        expect(Report::query()->sole()->status)->toBe(ReportStatus::InReview);
    });

    it('"wait" queues a generation that holds back until they are done', function () {
        send('/report', 10);
        $wait = collect(collect(fakeTelegram()->sent)->last()['keyboard'])->flatten(1)[0]['callback_data'];
        Queue::fake();

        postTelegram(callbackPayload($wait, 555001, 'cb-w', 802))->assertOk();

        Queue::assertPushed(GenerateReport::class, fn (GenerateReport $job) => $job->waitForEntries === true && $job->channel === 'telegram');
        expect(isVariantOf(collect(fakeTelegram()->edits)->last()['text'], 'report.generating', 'id'))->toBeTrue();
    });
});

describe('a report that already exists', function () {
    it('offers a new version for an approved report, and "leave it" changes nothing', function () {
        send('/report', 10);
        $report = Report::query()->sole();
        app(ReportWorkflow::class)->approve($report, $report->current_version_id);
        $report->refresh();

        send('/report', 11);
        $ask = collect(fakeTelegram()->sent)->last();
        expect(isVariantOf($ask['text'], 'report.already_approved', 'id', ['period' => 'September 2026']))->toBeTrue();

        [$new, $leave] = collect($ask['keyboard'])->flatten(1)->pluck('callback_data')->all();
        postTelegram(callbackPayload($leave, 555001, 'cb-l', 803))->assertOk();
        expect($report->fresh()->currentVersion->version_no)->toBe(1)->and(isVariantOf(collect(fakeTelegram()->edits)->last()['text'], 'report.dismissed', 'id'))->toBeTrue();

        postTelegram(callbackPayload($new, 555001, 'cb-n', 804))->assertOk();
        expect($report->fresh()->currentVersion->version_no)->toBe(2)->and($report->fresh()->status)->toBe(ReportStatus::InReview)
            ->and(DB::table('report_versions')->where('version_no', 1)->whereNotNull('approved_at')->count())->toBe(1);
    });

    it('says so when the report is already being generated', function () {
        send('/report', 10);
        Report::query()->sole()->update(['generation_lock_until' => now()->addMinutes(3), 'status' => ReportStatus::Generating]);
        Queue::fake();

        send('/report', 11);

        expect(isVariantOf(collect(texts())->last(), 'report.busy', 'id'))->toBeTrue();
        Queue::assertNothingPushed();
    });
});

it('round-trips the start button payload and rejects anything else', function () {
    expect((new ReportStartCallback(12, '2026-09'))->encode())->toBe('g:12:202609')->and(ReportStartCallback::parse('g:12:202609'))->toEqual(new ReportStartCallback(12, '2026-09'));

    foreach (['', 'g:0:202609', 'g:1:202613', 'g:1:20260', 'g:x:202609', "g:1:202609\n", 'p:1:202609', 'g:1:202609:1'] as $bad) {
        expect(ReportStartCallback::parse($bad))->toBeNull();
    }
});
