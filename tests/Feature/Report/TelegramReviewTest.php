<?php

use App\Enums\ActivitySource;
use App\Enums\Language;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\TaskStatus;
use App\Jobs\SendReportReview;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Task;
use App\Services\Ai\AiRequest;
use App\Services\Ai\Fakes\FakeAiProvider;
use App\Services\Report\ReportGenerator;
use App\Services\Report\ReportWorkflow;
use App\Services\Telegram\BotMessages;
use App\Services\Telegram\HttpTelegramClient;
use App\Services\Telegram\ReportCallback;
use App\Services\Telegram\ReportReviewComposer;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramMessenger;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakeSecrets;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 1, 9, 0, 0, 'Asia/Jakarta'));
    $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta', 'default_language' => 'id']);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal', 'slug' => 'harbor-portal']);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Shipment Tracking API', 'status' => TaskStatus::InProgress, 'started_at' => '2026-09-02 02:00:00+00']);
    Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-03', 'summary' => 'Webhook receiver written']);
    Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-15', 'summary' => 'Retry logic added']);

    reportAi();
    $this->generator = app(ReportGenerator::class);
    $this->report = $this->generator->findOrCreate($this->project, ReportType::Monthly, '2026-09-01', '2026-09-30', Language::English);
    $this->v1 = $this->generator->generate($this->report);
});

afterEach(fn () => Carbon::setTestNow());

/** The model answers every report step sensibly; `$facts` / `$unmatched` script the instruction step. */
function reportAi(array $facts = [], array $unmatched = []): void
{
    fakeAi()->using(function (AiRequest $r) use ($facts, $unmatched) {
        $p = json_decode($r->user, true);

        return $r->purpose === 'report_instruction'
            ? json_encode(['new_facts' => $facts, 'unmatched_facts' => $unmatched])
            : json_encode(['markdown' => 'Pekerjaan berfokus pada {{task:'.$p['tasks'][0]['id'].'}}.', 'used_task_ids' => [$p['tasks'][0]['id']]]);
    });
}

/** Press a report button as Telegram would deliver it. */
function pressReport(Report $report, int $versionNo, string $action, ?int $arg = null, string $callbackId = 'cb-r', int $bubble = 700, int $from = 555001): void
{
    postTelegram(callbackPayload((new ReportCallback($report->id, $versionNo, $action, $arg))->encode(), $from, $callbackId, $bubble))->assertOk();
}

function sentActions(array $keyboard): array
{
    return collect($keyboard)->flatten(1)->map(fn ($b) => isset($b['callback_data']) ? ReportCallback::parse($b['callback_data'])->action : 'url')->all();
}

describe('the review message', function () {
    it('summarises the version and offers the choices, followed by the PDF', function () {
        SendReportReview::dispatchSync($this->v1->id, $this->user->id);

        $message = collect(fakeTelegram()->sent)->last();
        $docs = fakeTelegram()->documents;

        expect($message['text'])->toContain('Harbor Portal Monthly Report')->toContain('v1')->toContain('Aktivitas: 2')->toContain('Task berjalan: 1')
            ->and(sentActions($message['keyboard']))->toBe(['ok', 're', 'ed', 'cx'])   // no dashboard button: APP_URL is not a public https address
            ->and($docs)->toHaveCount(1)->and($docs[0]['filename'])->toBe('harbor-portal-monthly-report-september-2026-v1.pdf')->and($docs[0]['chat_id'])->toBe(555001)
            ->and($docs[0]['contents'])->toBe(Storage::disk('reports')->get(ReportFile::query()->where('format', 'pdf')->value('file_path')));
    });

    it('says when sections use a fixed sentence because the AI text was rejected', function () {
        fakeAi()->using(fn () => 'not json');
        $v2 = $this->generator->generate($this->report->fresh());

        SendReportReview::dispatchSync($v2->id, $this->user->id);

        expect(collect(fakeTelegram()->sent)->last()['text'])->toContain('4 bagian memakai kalimat tetap');
    });

    it('offers "Open in Dashboard" only for a public https address', function () {
        config(['app.url' => 'https://reports.example.org']);
        URL::forceRootUrl('https://reports.example.org');
        URL::forceScheme('https');
        SendReportReview::dispatchSync($this->v1->id, $this->user->id);

        $buttons = collect(collect(fakeTelegram()->sent)->last()['keyboard'])->flatten(1);
        expect($buttons->firstWhere('url')['url'])->toStartWith('https://reports.example.org/admin/reports/')->and(sentActions(collect(fakeTelegram()->sent)->last()['keyboard']))->toContain('url');
        URL::forceRootUrl(null);
        URL::forceScheme(null);
    });

    it('sends the PDF and the Markdown file once the report is approved', function () {
        SendReportReview::dispatchSync($this->v1->id, $this->user->id, SendReportReview::FILES);

        $docs = collect(fakeTelegram()->documents);
        expect($docs->pluck('filename')->all())->toBe(['harbor-portal-monthly-report-september-2026-v1.pdf', 'harbor-portal-monthly-report-september-2026-v1.md'])
            ->and($docs->last()['contents'])->toStartWith('# Harbor Portal Monthly Report')->and(fakeTelegram()->sent)->toBe([]);
    });

    it('waits for the files, then sends what exists with a note instead of nothing', function () {
        ReportFile::query()->delete();
        $job = new SendReportReview($this->v1->id, $this->user->id);
        $job->withFakeQueueInteractions();

        $job->handle(app(TelegramMessenger::class), app(BotMessages::class), app(ReportReviewComposer::class));
        $job->assertReleased(10);
        expect(fakeTelegram()->sent)->toBe([]);

        Carbon::setTestNow(now()->addSeconds(SendReportReview::WAIT_SECONDS + 1));
        $job->handle(app(TelegramMessenger::class), app(BotMessages::class), app(ReportReviewComposer::class));

        expect(collect(fakeTelegram()->sent)->last()['text'])->toMatch('/File PDF masih disiapkan|PDF-nya belum selesai/')->and(fakeTelegram()->documents)->toBe([]);
    });

    it('does not repeat a step that already went out when the job is retried', function () {
        $job = new SendReportReview($this->v1->id, $this->user->id);
        $args = [app(TelegramMessenger::class), app(BotMessages::class), app(ReportReviewComposer::class)];

        $job->handle(...$args);
        $job->handle(...$args);

        expect(fakeTelegram()->sent)->toHaveCount(1)->and(fakeTelegram()->documents)->toHaveCount(1);
    });

    it('is not sent for a user without Telegram', function () {
        $this->user->update(['telegram_user_id' => null]);

        SendReportReview::dispatchSync($this->v1->id, $this->user->id);

        expect(fakeTelegram()->sent)->toBe([])->and(fakeTelegram()->documents)->toBe([]);
    });
});

describe('the buttons', function () {
    it('approves, closes the buttons and delivers both files', function () {
        pressReport($this->report, 1, 'ok');

        expect($this->report->fresh()->status)->toBe(ReportStatus::Approved)->and($this->report->fresh()->currentVersion->approved_at)->not->toBeNull()
            ->and(isVariantOf(collect(fakeTelegram()->edits)->last()['text'], 'report.approved', 'id'))->toBeTrue()->and(collect(fakeTelegram()->edits)->last()['keyboard'])->toBe([])
            ->and(collect(fakeTelegram()->documents)->pluck('filename')->map(fn ($f) => pathinfo($f, PATHINFO_EXTENSION))->all())->toBe(['pdf', 'md']);
    });

    it('answers a press on an old review with the newest one and changes nothing', function () {
        $v2 = app(ReportWorkflow::class)->saveEdit($this->report->fresh(), $this->v1->id, ['summary' => 'Edited in the dashboard']);

        pressReport($this->report, 1, 'ok');

        expect($this->report->fresh()->status)->toBe(ReportStatus::InReview)
            ->and(isVariantOf(collect(fakeTelegram()->answers)->last()['text'], 'report.stale', 'id'))->toBeTrue()
            ->and(collect(fakeTelegram()->edits)->last()['text'])->toContain('v2')->and(sentActions(collect(fakeTelegram()->edits)->last()['keyboard']))->toContain('ok');
        expect($v2->approved_at)->toBeNull();
    });

    it('regenerates from a fresh snapshot and sends the new review', function () {
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-20', 'summary' => 'Late note']);

        pressReport($this->report, 1, 're');

        expect($this->report->fresh()->currentVersion->version_no)->toBe(2)->and($this->report->fresh()->currentVersion->source_activity_ids)->toHaveCount(3)
            ->and(isVariantOf(collect(fakeTelegram()->edits)->last()['text'], 'report.generating', 'id'))->toBeTrue()
            ->and(collect(fakeTelegram()->sent)->last()['text'])->toContain('v2')->toContain('Aktivitas: 3');
    });

    it('cancels the report', function () {
        pressReport($this->report, 1, 'cx');

        expect($this->report->fresh()->status)->toBe(ReportStatus::Cancelled)->and(isVariantOf(collect(fakeTelegram()->edits)->last()['text'], 'report.cancelled', 'id'))->toBeTrue();
    });

    it('offers no approve or cancel once the report is approved', function () {
        pressReport($this->report, 1, 'ok');
        SendReportReview::dispatchSync($this->v1->id, $this->user->id);

        $last = collect(fakeTelegram()->sent)->last();
        expect(sentActions($last['keyboard']))->toBe([])->and($last['text'])->toContain('disetujui');
    });

    it('cannot be pressed by another user', function () {
        registerTelegramUser(555002, ['timezone' => 'Asia/Jakarta']);

        pressReport($this->report, 1, 'ok', from: 555002, callbackId: 'cb-x');

        expect($this->report->fresh()->status)->toBe(ReportStatus::InReview)->and(isVariantOf(collect(fakeTelegram()->answers)->last()['text'], 'callback.expired', 'id'))->toBeTrue();
    });
});

describe('edit via instruction', function () {
    it('lists the sections, asks for the instruction, then applies the next message to that section', function () {
        pressReport($this->report, 1, 'ed');
        $menu = collect(fakeTelegram()->edits)->last();
        expect(sentActions($menu['keyboard']))->toBe(['sec', 'sec', 'sec', 'sec', 'sec', 'sec', 'sec', 'bk']);

        pressReport($this->report, 1, 'sec', 6, 'cb-s');   // Monthly Summary
        expect(collect(fakeTelegram()->edits)->last()['text'])->toContain('Monthly Summary');

        reportAi();
        send('Persingkat bagian ini', 300);

        $report = $this->report->fresh();
        expect($report->currentVersion->version_no)->toBe(2)->and($report->currentVersion->created_by->value)->toBe('instruction_edit')->and($report->currentVersion->instruction)->toBe('Persingkat bagian ini')
            ->and(InboundMessage::query()->count())->toBe(0)   // an instruction is not a worklog note
            ->and(collect(fakeTelegram()->sent)->contains(fn ($m) => isVariantOf($m['text'], 'report.instructed', 'id', ['count' => 0])))->toBeTrue()
            ->and(collect(fakeTelegram()->sent)->last()['text'])->toContain('v2');
    });

    it('saves a new fact as an activity first and tells the person when a fact fits no task', function () {
        pressReport($this->report, 1, 'sec', 5);   // incidents
        reportAi([['task_id' => $this->task->id, 'summary' => 'The outage lasted 25 minutes.', 'date' => '2026-09-14', 'activity_type' => 'blocker']]);
        send('Tambahkan: API Tracking mati 25 menit tanggal 14', 301);

        expect(Activity::query()->where('source', ActivitySource::ReportEdit)->count())->toBe(1);

        $this->report->refresh();
        pressReport($this->report, 2, 'sec', 5, 'cb-s2');
        reportAi([], ['Pindah server bulan Agustus']);
        send('Tambahkan pindah server', 302);

        expect(collect(fakeTelegram()->sent)->contains(fn ($m) => isVariantOf($m['text'], 'report.instruction.unmatched', 'id', ['facts' => 'Pindah server bulan Agustus'])))->toBeTrue()
            ->and($this->report->fresh()->currentVersion->version_no)->toBe(2);
    });

    it('keeps credentials out of the stored instruction', function () {
        pressReport($this->report, 1, 'sec', 6);
        reportAi();
        $key = FakeSecrets::openAiKey();

        send("Persingkat, kuncinya {$key}", 303);

        expect($this->report->fresh()->currentVersion->instruction)->not->toContain($key)->and(json_encode(fakeAi()->requests))->not->toContain($key);
    });

    it('goes back to the review without waiting for an instruction, so the next message is a normal note', function () {
        pressReport($this->report, 1, 'sec', 6);
        pressReport($this->report, 1, 'bk', callbackId: 'cb-b');
        fakeAi()->using(fn () => FakeAiProvider::EMPTY_EXTRACTION);

        send('Hari ini fix bug login', 304);

        expect(InboundMessage::query()->count())->toBe(1)->and($this->report->fresh()->currentVersion->version_no)->toBe(1);
    });

    it('forgets the wait after ten minutes', function () {
        pressReport($this->report, 1, 'sec', 6);
        Carbon::setTestNow(now()->addMinutes(11));

        fakeAi()->using(fn () => FakeAiProvider::EMPTY_EXTRACTION);
        send('Hari ini fix bug login', 305);

        expect(InboundMessage::query()->count())->toBe(1);
    });

    it('refuses an instruction when the report changed after the section was chosen', function () {
        pressReport($this->report, 1, 'sec', 6);
        app(ReportWorkflow::class)->saveEdit($this->report->fresh(), $this->v1->id, ['summary' => 'Changed in the dashboard']);

        send('Persingkat bagian ini', 306);

        expect(isVariantOf(collect(fakeTelegram()->sent)->last()['text'], 'report.stale', 'id'))->toBeTrue()->and($this->report->fresh()->currentVersion->version_no)->toBe(2);
    });
});

describe('commands', function () {
    it('/review sends the draft that waits for review, or says there is none', function () {
        send('/review', 10);
        expect(collect(fakeTelegram()->sent)->last()['text'])->toContain('Harbor Portal Monthly Report')->and(fakeTelegram()->documents)->toHaveCount(1);

        $this->report->update(['status' => ReportStatus::Cancelled]);
        send('/review', 11);
        expect(isVariantOf(collect(fakeTelegram()->sent)->last()['text'], 'report.nothing_to_review', 'id'))->toBeTrue();
    });

    it('/reports lists the latest reports with their status', function () {
        send('/reports', 10);

        expect(collect(fakeTelegram()->sent)->last()['text'])->toContain('Harbor Portal · 01 Sep 2026 – 30 Sep 2026 · Dalam tinjauan (v1)');
    });
});

describe('the Telegram client', function () {
    it('uploads a document as multipart with the buttons, and never exposes the token on failure', function () {
        config(['telegram.token' => implode(':', ['123456', 'AAA'.str_repeat('b9', 14)]), 'telegram.api_base' => 'https://api.telegram.test']);
        Http::fake(['api.telegram.test/*' => Http::sequence()->push(['ok' => true, 'result' => ['message_id' => 77]])->push(['ok' => false, 'description' => 'Bad Request'], 400)]);
        $client = new HttpTelegramClient;

        $id = $client->sendDocument(555001, 'report-v1.pdf', "%PDF-1.4\nbody", 'Caption', [[['text' => 'Open', 'url' => 'https://x.example']]]);

        expect($id)->toBe(77);
        Http::assertSent(function (Request $request) {
            $parts = collect($request->data())->keyBy('name');

            return str_ends_with($request->url(), '/sendDocument') && $request->isMultipart()
                && $parts['chat_id']['contents'] === '555001' && $parts['caption']['contents'] === 'Caption'
                && $parts['document']['filename'] === 'report-v1.pdf' && $parts['document']['contents'] === "%PDF-1.4\nbody"
                && str_contains($parts['reply_markup']['contents'], 'x.example');
        });

        try {
            $client->sendDocument(555001, 'r.pdf', 'x');
            $this->fail('expected a failure');
        } catch (TelegramApiException $e) {
            expect($e->getMessage().json_encode($e->getTrace()))->not->toContain('AAA');
        }
    });
});
