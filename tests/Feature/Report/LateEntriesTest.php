<?php

use App\Enums\EventActor;
use App\Enums\Language;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\TaskStatus;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Jobs\SendReportReview;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportVersion;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiRequest;
use App\Services\Report\ReportDrift;
use App\Services\Report\ReportEditor;
use App\Services\Report\ReportGenerator;
use App\Services\Report\ReportWorkflow;
use App\Services\Telegram\ReportCallback;
use App\Services\Worklog\TaskLifecycle;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 1, 9, 0, 0, 'Asia/Jakarta'));
    $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta', 'default_language' => 'id']);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal', 'slug' => 'harbor-portal']);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Shipment Tracking API', 'status' => TaskStatus::InProgress, 'started_at' => '2026-09-02 02:00:00+00']);
    $this->first = Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-03', 'summary' => 'Webhook receiver written']);
    Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-15', 'summary' => 'Retry logic added']);

    fakeAi()->using(function (AiRequest $r) {
        $p = json_decode($r->user, true);

        return $r->purpose === 'report_instruction'
            ? json_encode(['new_facts' => [['task_id' => test()->task->id, 'summary' => 'The outage lasted 25 minutes.', 'date' => '2026-09-14', 'activity_type' => 'blocker']], 'unmatched_facts' => []])
            : json_encode(['markdown' => 'Work centred on {{task:'.$p['tasks'][0]['id'].'}}.', 'used_task_ids' => [$p['tasks'][0]['id']]]);
    });

    $this->generator = app(ReportGenerator::class);
    $this->report = $this->generator->findOrCreate($this->project, ReportType::Monthly, '2026-09-01', '2026-09-30', Language::English);
    $this->v1 = $this->generator->generate($this->report);
    $this->drift = app(ReportDrift::class);
});

afterEach(fn () => Carbon::setTestNow());

function later(int $minutes = 30): void
{
    Carbon::setTestNow(now()->addMinutes($minutes));
}

function checkDrift(): void
{
    Artisan::call('reports:check-drift');
    app(UserContext::class)->set(test()->user->id);
}

function approveReport(): void
{
    app(ReportWorkflow::class)->approve(test()->report->fresh(), test()->report->fresh()->current_version_id);
    test()->report->refresh();
}

describe('what counts as a change', function () {
    it('is nothing while the period data stays as it was', function () {
        later(120);

        expect($this->drift->changes($this->report->fresh()))->toBe(['new' => 0, 'changed' => 0, 'removed' => 0, 'tasks' => 0, 'total' => 0]);
    });

    it('counts an activity for the period written after the snapshot, but not other periods or projects', function () {
        later();
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-20', 'summary' => 'Late note']);
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-10-02', 'summary' => 'Next month']);
        Activity::factory()->for(Task::factory()->for(Project::factory()->create())->create())->create(['activity_date' => '2026-09-20']);

        expect($this->drift->changes($this->report->fresh()))->toMatchArray(['new' => 1, 'changed' => 0, 'removed' => 0, 'total' => 1]);
    });

    it('counts an edited source activity as changed', function () {
        later();
        $this->first->update(['summary' => 'Webhook receiver rewritten']);

        expect($this->drift->changes($this->report->fresh()))->toMatchArray(['new' => 0, 'changed' => 1, 'removed' => 0, 'tasks' => 0, 'total' => 1]);
    });

    it('counts a deleted source activity as removed', function () {
        later();
        $this->first->delete();

        expect($this->drift->changes($this->report->fresh()))->toMatchArray(['new' => 0, 'changed' => 0, 'removed' => 1, 'total' => 1]);
    });

    it('counts a task of the report that changed status after the snapshot', function () {
        later();
        app(TaskLifecycle::class)->changeStatus($this->task->fresh(), TaskStatus::Completed, EventActor::User);

        expect($this->drift->changes($this->report->fresh()))->toMatchArray(['tasks' => 1, 'total' => 1]);
    });

    it('does not count what an instruction edit added to the report itself', function () {
        later();
        $result = app(ReportEditor::class)->instruct($this->report->fresh(), $this->v1->id, 'incidents', 'Add that the API outage lasted 25 minutes on 14 September.');

        expect($result->ok())->toBeTrue()->and($this->drift->count($this->report->fresh()))->toBe(0);
    });

    it('counts again only what changed after "Abaikan"', function () {
        approveReport();
        later();
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-20']);
        checkDrift();
        app(ReportWorkflow::class)->dismissDrift($this->report->fresh());
        expect($this->drift->count($this->report->fresh()))->toBe(0);

        later();
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-21']);

        expect($this->drift->count($this->report->fresh()))->toBe(1);
    });

    it('does not see another user\'s data', function () {
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        later();
        asUser($other->id, fn () => Activity::factory()->for(Task::factory()->for(Project::factory()->create(['user_id' => $other->id]))->create())->create(['activity_date' => '2026-09-20']));

        expect($this->drift->count($this->report->fresh()))->toBe(0);
    });
});

describe('the scheduled check', function () {
    beforeEach(function () {
        approveReport();
        fakeTelegram()->sent = [];
    });

    it('does nothing for an approved report that did not change', function () {
        later();
        checkDrift();

        expect($this->report->fresh()->status)->toBe(ReportStatus::Approved)->and(fakeTelegram()->sent)->toBe([]);
    });

    it('marks an approved report outdated, tells the person once, and leaves the approved version alone', function () {
        later();
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-20', 'summary' => 'Late note']);
        checkDrift();
        checkDrift();   // the scheduler runs again

        $report = $this->report->fresh();
        $sent = fakeTelegram()->sent;
        $buttons = collect($sent[0]['keyboard'])->flatten(1);
        expect($report->status)->toBe(ReportStatus::Outdated)->and($sent)->toHaveCount(1)
            ->and($sent[0]['chat_id'])->toBe(555001)->and($sent[0]['text'])->toContain('September 2026')->toContain('Harbor Portal')->toContain('1 perubahan')
            ->and($buttons->map(fn ($b) => ReportCallback::parse($b['callback_data'])->action)->all())->toBe(['nv', 'ig'])
            ->and($report->currentVersion->approved_at)->not->toBeNull()->and($report->currentVersion->id)->toBe($this->v1->id);
    });

    it('leaves drafts alone: the dashboard shows their drift instead', function () {
        $draft = $this->generator->findOrCreate($this->project, ReportType::Custom, '2026-09-10', '2026-09-20', Language::English);
        $this->generator->generate($draft);
        fakeTelegram()->sent = [];
        later();
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-12']);

        checkDrift();

        expect($draft->fresh()->status)->toBe(ReportStatus::InReview)->and($this->drift->count($draft->fresh()))->toBe(1);
    });

    it('goes back to approved, quietly, when the changes are gone', function () {
        later();
        $late = Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-20']);
        checkDrift();
        fakeTelegram()->sent = [];

        $late->delete();
        checkDrift();

        expect($this->report->fresh()->status)->toBe(ReportStatus::Approved)->and(fakeTelegram()->sent)->toBe([]);
    });

    it('skips users without Telegram and never touches another user\'s reports', function () {
        $other = User::factory()->create(['telegram_user_id' => null]);
        $theirs = asUser($other->id, function () use ($other) {
            $project = Project::factory()->create(['user_id' => $other->id]);
            $report = Report::factory()->create(['project_id' => $project->id, 'status' => ReportStatus::Approved, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30']);
            $version = ReportVersion::factory()->create(['report_id' => $report->id, 'data_snapshot_at' => now()->subDay(), 'content' => ['title' => 'x', 'language' => 'en', 'sections' => []]]);
            $report->update(['current_version_id' => $version->id]);
            Activity::factory()->for(Task::factory()->for($project)->create())->create(['activity_date' => '2026-09-10']);

            return $report;
        });

        checkDrift();

        expect($theirs->fresh()->status)->toBe(ReportStatus::Approved)->and(fakeTelegram()->sent)->toBe([]);
    });
});

describe('answering the notice', function () {
    beforeEach(function () {
        approveReport();
        later();
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-20', 'summary' => 'Late note']);
        fakeTelegram()->sent = [];
        checkDrift();
        [$this->newVersion, $this->ignore] = collect(fakeTelegram()->sent[0]['keyboard'])->flatten(1)->pluck('callback_data')->all();
    });

    it('"Abaikan" makes it approved again, and only new changes bring it back', function () {
        postTelegram(callbackPayload($this->ignore, 555001, 'cb-i', 900))->assertOk();

        $report = $this->report->fresh();
        expect($report->status)->toBe(ReportStatus::Approved)->and($report->drift_dismissed_at)->not->toBeNull()
            ->and(isVariantOf(collect(fakeTelegram()->edits)->last()['text'], 'report.dismissed', 'id'))->toBeTrue();

        checkDrift();
        expect($this->report->fresh()->status)->toBe(ReportStatus::Approved)->and(fakeTelegram()->sent)->toHaveCount(1);

        later();
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-22']);
        checkDrift();
        expect($this->report->fresh()->status)->toBe(ReportStatus::Outdated)->and(fakeTelegram()->sent)->toHaveCount(2);
    });

    it('"Buat Versi Baru" makes version 2 from a fresh snapshot, in review, with the approved version intact', function () {
        postTelegram(callbackPayload($this->newVersion, 555001, 'cb-n', 901))->assertOk();

        $report = $this->report->fresh();
        expect($report->status)->toBe(ReportStatus::InReview)->and($report->currentVersion->version_no)->toBe(2)->and($report->currentVersion->source_activity_ids)->toHaveCount(3)
            ->and($this->v1->fresh()->approved_at)->not->toBeNull()->and($this->drift->count($report))->toBe(0);
    });

    it('shows the same two choices when the review of an outdated report is opened', function () {
        SendReportReview::dispatchSync($this->v1->id, $this->user->id);

        $actions = collect(collect(fakeTelegram()->sent)->last()['keyboard'])->flatten(1)->map(fn ($b) => isset($b['callback_data']) ? ReportCallback::parse($b['callback_data'])->action : 'url')->all();
        expect($actions)->toBe(['nv', 'ig']);
    });
});

describe('on the dashboard', function () {
    beforeEach(function () {
        $this->actingAs($this->user);
        app()->setLocale('en');
    });

    it('tells a draft how many changes happened since it was made, and updates it on request', function () {
        later();
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-20', 'summary' => 'Late note']);

        $page = Livewire::test(ViewReport::class, ['record' => $this->report->getKey()])->assertSee('1 change(s) to this period')->assertSee('Update Draft');
        $page->call('regenerate');

        $report = $this->report->fresh();
        expect($report->currentVersion->version_no)->toBe(2)->and($this->drift->count($report))->toBe(0);
        $page->call('refreshState')->assertDontSee('Update Draft');
    });

    it('lets an approved, outdated report get a new version or be ignored', function () {
        approveReport();
        later();
        Activity::factory()->for($this->task)->create(['activity_date' => '2026-09-20']);
        checkDrift();

        Livewire::test(ViewReport::class, ['record' => $this->report->getKey()])->assertSee('approved, but 1 change(s)')->assertSee('Create New Version')->assertSee('Ignore')
            ->call('ignoreDrift')->assertNotified(__('ui.dashboard.reports.notices.drift_dismissed'));

        expect($this->report->fresh()->status)->toBe(ReportStatus::Approved);
    });

    it('shows no banner while nothing changed', function () {
        Livewire::test(ViewReport::class, ['record' => $this->report->getKey()])->assertDontSee('change(s) to this period');
    });
});
