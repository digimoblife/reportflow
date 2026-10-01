<?php

use App\Enums\ActivityType;
use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\ReportCreatedBy;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\TaskStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Jobs\GenerateReport;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\ReportVersion;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiRequest;
use App\Services\Report\Pdf\PdfRenderer;
use App\Services\Report\ReportFiles;
use App\Services\Report\ReportGenerator;
use App\Services\Report\ReportHtml;
use App\Services\Report\ReportWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 1, 9, 0, 0, 'Asia/Jakarta'));
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta', 'default_language' => 'en']);
    actingAsUser($this->user);
    $this->actingAs($this->user);
    app()->setLocale('en');
    $this->project = Project::factory()->create(['name' => 'Harbor Portal', 'slug' => 'harbor-portal']);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Shipment Tracking API', 'status' => TaskStatus::InProgress]);
    Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-10', 'summary' => 'Webhook receiver written', 'activity_type' => ActivityType::Development]);
});

afterEach(fn () => Carbon::setTestNow());

function narrativeAi(): void
{
    fakeAi()->using(function (AiRequest $r) {
        $p = json_decode($r->user, true);
        $ids = array_slice(array_column($p['tasks'], 'id'), 0, 1);

        return json_encode(['markdown' => 'Work centred on '.implode(' ', array_map(fn ($i) => '{{task:'.$i.'}}', $ids)).'.', 'used_task_ids' => $ids]);
    });
}

/** A report with one generated version, ready to review. */
function reviewReport(): Report
{
    narrativeAi();
    $report = app(ReportGenerator::class)->findOrCreate(test()->project, ReportType::Monthly, '2026-09-01', '2026-09-30', Language::English);
    app(ReportGenerator::class)->generate($report);

    return $report->fresh();
}

describe('report list', function () {
    it('shows only the user\'s reports and can filter by status', function () {
        $mine = reviewReport();
        $approved = Report::factory()->create(['project_id' => $this->project->id, 'status' => ReportStatus::Approved, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31']);
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $theirs = asUser($other->id, fn () => Report::factory()->create(['project_id' => Project::factory()->create(['user_id' => $other->id])->id]));

        Livewire::test(ListReports::class)
            ->assertCanSeeTableRecords([$mine, $approved])->assertCanNotSeeTableRecords([$theirs])
            ->filterTable('status', ['approved'])->assertCanSeeTableRecords([$approved])->assertCanNotSeeTableRecords([$mine]);
    });

    it('is reachable from the panel', function () {
        $this->get('/admin/reports')->assertOk()->assertSee('Reports');
    });
});

describe('generating from the dashboard', function () {
    it('creates the report for the chosen period and queues the generation, which produces a version and files', function () {
        narrativeAi();

        Livewire::test(ListReports::class)
            ->callAction('generate', ['project_id' => $this->project->id, 'from' => '2026-09-01', 'until' => '2026-09-30', 'language' => 'en'])
            ->assertNotified(__('ui.dashboard.reports.notices.queued'));

        $report = Report::query()->sole();
        expect($report->type->value)->toBe('monthly')->and($report->status)->toBe(ReportStatus::InReview)
            ->and($report->currentVersion->content['title'])->toBe('Harbor Portal Monthly Report — September 2026')
            ->and(ReportFile::query()->count())->toBe(2);
    });

    it('treats a partial month as a custom report', function () {
        Queue::fake();

        Livewire::test(ListReports::class)->callAction('generate', ['project_id' => $this->project->id, 'from' => '2026-09-15', 'until' => '2026-09-30', 'language' => 'id']);

        expect(Report::query()->sole()->type->value)->toBe('custom')->and(Report::query()->sole()->status)->toBe(ReportStatus::Generating);
        Queue::assertPushed(GenerateReport::class);
    });

    it('refuses an impossible period and creates nothing', function () {
        Queue::fake();

        Livewire::test(ListReports::class)->callAction('generate', ['project_id' => $this->project->id, 'from' => '2026-09-30', 'until' => '2026-09-01', 'language' => 'en'])
            ->assertNotified(__('ui.dashboard.reports.notices.invalid'));

        expect(Report::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('does not start a second generation while one is running', function () {
        Queue::fake();
        $report = Report::factory()->create(['project_id' => $this->project->id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'status' => ReportStatus::Generating, 'generation_lock_until' => now()->addMinutes(3)]);

        Livewire::test(ListReports::class)->callAction('generate', ['project_id' => $this->project->id, 'from' => '2026-09-01', 'until' => '2026-09-30', 'language' => 'en'])
            ->assertNotified(__('ui.dashboard.reports.notices.busy'));

        Queue::assertNothingPushed();
        expect($report->fresh()->status)->toBe(ReportStatus::Generating);
    });

    it('offers "wait" or "generate without" when entries are still being processed, and passes the choice on', function () {
        Queue::fake();
        InboundMessage::factory()->count(2)->create(['user_id' => $this->user->id, 'status' => InboundMessageStatus::Processing]);

        Livewire::test(ListReports::class)
            ->mountAction('generate')
            ->setActionData(['project_id' => $this->project->id, 'from' => '2026-09-01', 'until' => '2026-09-30', 'language' => 'en', 'pending_choice' => 'wait'])
            ->callMountedAction();

        Queue::assertPushed(GenerateReport::class, fn (GenerateReport $job) => $job->waitForEntries === true && $job->waitUntil > now()->getTimestamp());
    });

    it('does not wait when nothing is pending (no choice is asked)', function () {
        Queue::fake();

        Livewire::test(ListReports::class)->callAction('generate', ['project_id' => $this->project->id, 'from' => '2026-09-01', 'until' => '2026-09-30', 'language' => 'en']);

        Queue::assertPushed(GenerateReport::class, fn (GenerateReport $job) => $job->waitForEntries === false);
    });
});

describe('report page', function () {
    it('previews exactly the HTML the PDF is made from, and offers both downloads', function () {
        $report = reviewReport();

        $page = Livewire::test(ViewReport::class, ['record' => $report->getKey()]);
        $version = $report->currentVersion;

        expect($page->instance()->previewHtml())->toBe(app(ReportHtml::class)->render($version))
            ->and(app(PdfRenderer::class)->rendered[0]['html'])->toBe($page->instance()->previewHtml())
            ->and(array_keys($page->instance()->downloads()))->toEqualCanonicalizing(['md', 'pdf']);
        $page->assertSee('Harbor Portal Monthly Report — September 2026')->assertSee('Monthly Overview');
    });

    it('cannot open another user\'s report', function () {
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $theirs = asUser($other->id, fn () => Report::factory()->create(['project_id' => Project::factory()->create(['user_id' => $other->id])->id]));

        $this->get('/admin/reports/'.$theirs->id)->assertNotFound();
    });

    it('shows that it is generating, and says no files exist yet', function () {
        $report = Report::factory()->create(['project_id' => $this->project->id, 'status' => ReportStatus::Generating]);

        Livewire::test(ViewReport::class, ['record' => $report->getKey()])->assertSee('The report is being generated');
    });

    it('saves edited sections as a new version and shows it', function () {
        $report = reviewReport();

        Livewire::test(ViewReport::class, ['record' => $report->getKey()])
            ->set('sections.summary', 'Edited closing paragraph.')->call('saveSections')->assertNotified(__('ui.dashboard.reports.notices.saved'));

        $report = $report->fresh();
        expect($report->currentVersion->version_no)->toBe(2)->and($report->currentVersion->created_by)->toBe(ReportCreatedBy::UserEdit)
            ->and(collect($report->currentVersion->content['sections'])->firstWhere('key', 'summary')['markdown'])->toBe('Edited closing paragraph.');
    });

    it('says so when there is nothing to save', function () {
        $report = reviewReport();

        Livewire::test(ViewReport::class, ['record' => $report->getKey()])->call('saveSections')->assertNotified(__('ui.dashboard.reports.notices.unchanged'));

        expect(ReportVersion::query()->count())->toBe(1);
    });

    it('refuses to save over a version that appeared meanwhile, and keeps the person\'s text', function () {
        $report = reviewReport();
        $page = Livewire::test(ViewReport::class, ['record' => $report->getKey()]);

        app(ReportWorkflow::class)->saveEdit($report->fresh(), $report->current_version_id, ['summary' => 'Edited in another tab']);   // elsewhere

        $page->set('sections.summary', 'My own wording')->call('saveSections')->assertNotified(__('ui.dashboard.reports.notices.stale'));

        expect(ReportVersion::query()->count())->toBe(2)->and($page->get('sections')['summary'])->toBe('My own wording');
    });

    it('follows a new version by itself unless the person has unsaved edits', function () {
        $report = reviewReport();
        $page = Livewire::test(ViewReport::class, ['record' => $report->getKey()]);
        $v1 = $report->current_version_id;

        app(ReportWorkflow::class)->saveEdit($report->fresh(), $v1, ['summary' => 'Newer text']);
        $page->call('refreshState')->assertSet('sections.summary', 'Newer text')->assertSet('newerAvailable', false);

        $page->set('sections.overview', 'Half-typed text');
        app(ReportWorkflow::class)->saveEdit($report->fresh(), $report->fresh()->current_version_id, ['summary' => 'Even newer']);
        $page->call('refreshState')->assertSet('sections.overview', 'Half-typed text')->assertSet('newerAvailable', true)->assertSee('A newer version exists');

        $page->call('loadNewest')->assertSet('newerAvailable', false)->assertSet('sections.summary', 'Even newer');
    });

    it('approves, after which the version is immutable and editing starts a new one', function () {
        $report = reviewReport();
        $page = Livewire::test(ViewReport::class, ['record' => $report->getKey()]);

        $page->call('approve')->assertNotified(__('ui.dashboard.reports.notices.approved'));
        expect($report->fresh()->status)->toBe(ReportStatus::Approved)->and($report->fresh()->currentVersion->approved_at)->not->toBeNull();

        $page->assertSee('This version is approved')->set('sections.summary', 'Post-approval edit')->call('saveSections');
        $fresh = $report->fresh();
        expect($fresh->status)->toBe(ReportStatus::InReview)->and($fresh->currentVersion->version_no)->toBe(2)
            ->and(ReportVersion::query()->where('version_no', 1)->first()->approved_at)->not->toBeNull();
    });

    it('cancels an unapproved report but not an approved one', function () {
        $report = reviewReport();
        Livewire::test(ViewReport::class, ['record' => $report->getKey()])->call('cancelReport')->assertNotified(__('ui.dashboard.reports.notices.cancelled'));
        expect($report->fresh()->status)->toBe(ReportStatus::Cancelled);

        $approved = Report::factory()->create(['project_id' => $this->project->id, 'status' => ReportStatus::Approved, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31']);
        $v = ReportVersion::factory()->create(['report_id' => $approved->id, 'content' => ['title' => 'A', 'language' => 'en', 'sections' => []], 'approved_at' => now()]);
        $approved->update(['current_version_id' => $v->id]);

        Livewire::test(ViewReport::class, ['record' => $approved->getKey()])->call('cancelReport')->assertNotified(__('ui.dashboard.reports.notices.not_possible'));
        expect($approved->fresh()->status)->toBe(ReportStatus::Approved);
    });

    it('lists versions and compares two of them line by line', function () {
        $report = reviewReport();
        app(ReportWorkflow::class)->saveEdit($report->fresh(), $report->current_version_id, ['summary' => 'A different closing']);

        $page = Livewire::test(ViewReport::class, ['record' => $report->fresh()->getKey()]);
        $page->assertSee('v1')->assertSee('v2')->assertSee('Edited manually')->assertSee('A different closing');

        $diff = collect($page->instance()->diff())->keyBy('key');
        expect($diff['summary']['state'])->toBe('changed')->and($diff['overview']['state'])->toBe('same');
    });

    it('does not offer files for a version whose files are not ready yet', function () {
        $report = reviewReport();
        ReportFile::query()->delete();

        Livewire::test(ViewReport::class, ['record' => $report->getKey()])->assertSee('Files are being prepared');
        app(ReportFiles::class)->ensure($report->currentVersion);
        Livewire::test(ViewReport::class, ['record' => $report->getKey()])->assertDontSee('Files are being prepared');
    });
});

describe('editing by instruction on the report page', function () {
    /** The model finds $facts (and $unmatched) in an instruction and writes a plain narrative. */
    function dashboardAi(array $facts = [], array $unmatched = []): void
    {
        fakeAi()->using(function (AiRequest $r) use ($facts, $unmatched) {
            $p = json_decode($r->user, true);

            return $r->purpose === 'report_instruction'
                ? json_encode(['new_facts' => $facts, 'unmatched_facts' => $unmatched])
                : json_encode(['markdown' => 'Work centred on {{task:'.$p['tasks'][0]['id'].'}}.', 'used_task_ids' => [$p['tasks'][0]['id']]]);
        });
    }

    it('applies an instruction with a new fact: activity saved, new version loaded', function () {
        $report = reviewReport();
        dashboardAi([['task_id' => $this->task->id, 'summary' => 'The outage lasted 25 minutes.', 'date' => '2026-09-14', 'activity_type' => 'blocker']]);

        $page = Livewire::test(ViewReport::class, ['record' => $report->getKey()])
            ->set('instructions.incidents', 'Add the downtime: outage of 25 minutes on 14 September')->call('instruct', 'incidents');

        $report = $report->fresh();
        expect($report->currentVersion->version_no)->toBe(2)->and($report->currentVersion->created_by)->toBe(ReportCreatedBy::InstructionEdit)
            ->and(Activity::query()->where('source', 'report_edit')->count())->toBe(1);
        $page->assertSet('loadedVersionId', $report->current_version_id)->assertSet('instructions.incidents', '')->assertSee('The outage lasted 25 minutes.')->assertSee('Edited by instruction');
    });

    it('explains when a fact fits no task, and changes nothing', function () {
        $report = reviewReport();
        dashboardAi([], ['A server move in August']);

        Livewire::test(ViewReport::class, ['record' => $report->getKey()])
            ->set('instructions.incidents', 'Mention the server move')->call('instruct', 'incidents')
            ->assertNotified(__('ui.dashboard.reports.notices.unmatched', ['facts' => 'A server move in August']));

        expect(ReportVersion::query()->count())->toBe(1);
    });

    it('offers to save numbers typed into a section as an activity, and saves it on request', function () {
        $report = reviewReport();
        $current = $report->currentVersion;
        $overview = collect($current->content['sections'])->firstWhere('key', 'overview')['markdown'];

        $page = Livewire::test(ViewReport::class, ['record' => $report->getKey()])
            ->set('sections.overview', $overview."\n\nThe outage lasted 25 minutes.")->call('saveSections')
            ->assertSet('factOffers', ['overview'])->assertSee('Save them as an activity too');

        $page->call('openFact', 'overview')->set('factTask', (string) $this->task->id)->set('factDate', '2026-09-14')->set('factSummary', 'The outage lasted 25 minutes.')->set('factType', 'blocker')
            ->call('saveFact')->assertNotified(__('ui.dashboard.reports.notices.fact_saved'))->assertSet('factOffers', []);

        $activity = Activity::query()->where('source', 'report_edit')->sole();
        expect($activity->summary)->toBe('The outage lasted 25 minutes.')->and($activity->task_id)->toBe($this->task->id);
    });

    it('refuses an invalid fact without saving', function () {
        $report = reviewReport();
        $overview = collect($report->currentVersion->content['sections'])->firstWhere('key', 'overview')['markdown'];

        Livewire::test(ViewReport::class, ['record' => $report->getKey()])
            ->set('sections.overview', $overview.' 25')->call('saveSections')
            ->call('openFact', 'overview')->set('factTask', '999999')->set('factSummary', 'Something happened')->call('saveFact')
            ->assertNotified(__('ui.dashboard.reports.notices.invalid'));

        expect(Activity::query()->where('source', 'report_edit')->count())->toBe(0);
    });
});
