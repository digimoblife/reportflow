<?php

use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\MessageSource;
use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\Report;
use App\Services\Ai\AiRequest;
use App\Services\Report\ReportDrift;
use App\Services\Report\ReportGenerator;
use App\Services\Report\ReportRequests;
use App\Services\Worklog\DashboardSubmission;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\Support\Extraction;

// PRD §23, "Contoh Skenario": the morning in Telegram, the afternoon in the dashboard, then Generate while four entries are
// still being processed. Real queues (the `database` driver) so that "still being processed" is really so.

beforeEach(function () {
    config(['queue.default' => 'database']);
    Carbon::setTestNow(Carbon::create(2026, 9, 30, 9, 0, 0, 'Asia/Jakarta'));
    $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta', 'default_language' => 'en']);
    $this->w = worklogWorld($this->user);
    $this->project = $this->w['harbor'];
    // The stage is empty: only what the story writes counts.
    Activity::query()->forceDelete();

    fakeAi()->using(function (AiRequest $r) {
        $payload = json_decode($r->user, true);

        if ($r->purpose === 'worklog_extraction') {
            $title = 'Task for '.substr(md5($payload['message']), 0, 6);

            return Extraction::json([Extraction::newItem(test()->project->id, $title, ['activity' => ['date' => '2026-09-30', 'summary' => $payload['message']]])]);
        }

        return json_encode(['markdown' => 'Work centred on {{task:'.$payload['tasks'][0]['id'].'}}.', 'used_task_ids' => [$payload['tasks'][0]['id']]]);
    });
});

afterEach(fn () => Carbon::setTestNow());

/** Run the workers of one queue until it is empty. */
function drain(string $queue): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queue, '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]);
    app(UserContext::class)->set(test()->user->id);
}

/** The morning: two notes through Telegram, processed. The afternoon: four notes typed in the dashboard, not yet processed. */
function morningAndAfternoon(): void
{
    send('Harbor Portal: pagi, cek webhook carrier', 100);
    send('Harbor Portal: pagi, rapikan tabel tracking', 101);
    drain('default');

    Carbon::setTestNow(now()->addHours(6));
    foreach (['sore satu', 'sore dua', 'sore tiga', 'sore empat'] as $i => $text) {
        app(DashboardSubmission::class)->submit("Harbor Portal: {$text}", 'aaaaaaaa-bbbb-cccc-dddd-00000000000'.$i);
    }
}

it('knows the morning is saved and the afternoon is still being processed', function () {
    morningAndAfternoon();

    expect(Activity::query()->count())->toBe(2)
        ->and(InboundMessage::query()->where('source', MessageSource::Telegram)->where('status', InboundMessageStatus::Processed)->count())->toBe(2)
        ->and(InboundMessage::query()->where('source', MessageSource::Dashboard)->where('status', InboundMessageStatus::Received)->count())->toBe(4)
        ->and(app(ReportGenerator::class)->pendingEntries())->toBe(4);   // "4 entri masih diproses"
});

it('"Tunggu Selesai": the report is made only after the four are saved, from all six tasks', function () {
    morningAndAfternoon();

    $result = app(ReportRequests::class)->request($this->project, '2026-09-01', '2026-09-30', Language::English, waitForEntries: true);
    expect($result['status'])->toBe(ReportRequests::QUEUED);

    drain('reports');   // the job holds itself back while entries are pending
    $report = Report::query()->sole();
    expect($report->currentVersion)->toBeNull()->and($report->status)->toBe(ReportStatus::Generating);

    drain('default');   // the four entries finish
    expect(Activity::query()->count())->toBe(6);

    Carbon::setTestNow(now()->addSeconds(30));
    drain('reports');   // now it goes ahead (and renders the files)
    drain('reports');

    $report->refresh();
    expect($report->status)->toBe(ReportStatus::InReview)->and($report->currentVersion->version_no)->toBe(1)
        ->and($report->currentVersion->source_activity_ids)->toHaveCount(6)
        ->and($report->currentVersion->content['counts']['activities'])->toBe(6)
        ->and(app(ReportDrift::class)->count($report))->toBe(0);
});

it('"Generate Tanpa Entri Ini": a draft from the two saved tasks, then "Perbarui Draft" appears once the four are saved', function () {
    morningAndAfternoon();

    app(ReportRequests::class)->request($this->project, '2026-09-01', '2026-09-30', Language::English, waitForEntries: false);
    drain('reports');
    drain('reports');

    $report = Report::query()->sole();
    expect($report->currentVersion->content['counts']['activities'])->toBe(2)->and($report->currentVersion->source_activity_ids)->toHaveCount(2);

    Carbon::setTestNow(now()->addMinutes(2));
    drain('default');   // the four entries are saved after the draft was made

    expect(Activity::query()->count())->toBe(6)->and(app(ReportDrift::class)->count($report->fresh()))->toBe(4);

    $this->actingAs($this->user);
    app()->setLocale('en');
    $page = Livewire::test(ViewReport::class, ['record' => $report->getKey()])->assertSee('4 change(s) to this period')->assertSee('Update Draft');

    $page->call('regenerate');
    drain('reports');
    drain('reports');

    $report->refresh();
    expect($report->currentVersion->version_no)->toBe(2)->and($report->currentVersion->content['counts']['activities'])->toBe(6)
        ->and(app(ReportDrift::class)->count($report))->toBe(0);
    $page->call('refreshState')->assertDontSee('Update Draft');
});
