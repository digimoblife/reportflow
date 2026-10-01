<?php

use App\Enums\Language;
use App\Enums\ReportCreatedBy;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\TaskStatus;
use App\Exceptions\ImmutableReportVersionException;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\ReportVersion;
use App\Models\Task;
use App\Models\User;
use App\Services\Report\ReportDiff;
use App\Services\Report\ReportGenerator;
use App\Services\Report\ReportWorkflow;
use App\Services\Report\StaleReportException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 1, 9, 0, 0, 'Asia/Jakarta'));
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta']);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal']);
    $this->report = Report::factory()->create(['project_id' => $this->project->id, 'status' => ReportStatus::InReview]);
    $this->v1 = ReportVersion::factory()->create([
        'report_id' => $this->report->id, 'version_no' => 1,
        'content' => ['title' => 'T', 'language' => 'en', 'sections' => [
            ['key' => 'overview', 'title' => 'Overview', 'markdown' => "Line A\nLine B", 'fallback' => true],
            ['key' => 'summary', 'title' => 'Summary', 'markdown' => 'Closing text', 'fallback' => false],
        ]],
        'source_activity_ids' => [11, 12],
    ]);
    $this->report->update(['current_version_id' => $this->v1->id]);
    $this->workflow = app(ReportWorkflow::class);
});

afterEach(fn () => Carbon::setTestNow());

describe('editing', function () {
    it('saves a changed section as a new version made by the user and leaves the old version alone', function () {
        $v2 = $this->workflow->saveEdit($this->report, $this->v1->id, ['overview' => "Line A\nLine B edited"]);

        $report = $this->report->fresh();
        expect($v2->version_no)->toBe(2)->and($v2->created_by)->toBe(ReportCreatedBy::UserEdit)
            ->and($v2->content['sections'][0]['markdown'])->toBe("Line A\nLine B edited")->and($v2->content['sections'][0]['fallback'])->toBeFalse()
            ->and($v2->content['sections'][1])->toEqual($this->v1->content['sections'][1])
            ->and($v2->source_activity_ids)->toBe([11, 12])->and($v2->data_snapshot_at->toIso8601String())->toBe($this->v1->data_snapshot_at->toIso8601String())
            ->and($report->current_version_id)->toBe($v2->id)->and($report->status)->toBe(ReportStatus::InReview)
            ->and($this->v1->fresh()->content['sections'][0]['markdown'])->toBe("Line A\nLine B");
        expect(ReportFile::query()->where('report_version_id', $v2->id)->count())->toBe(2);   // files for the new version
    });

    it('makes no version when nothing changed or the key is unknown', function () {
        expect($this->workflow->saveEdit($this->report, $this->v1->id, ['overview' => "  Line A\nLine B  ", 'nonsense' => 'x']))->toBeNull()
            ->and(ReportVersion::query()->count())->toBe(1);
    });

    it('refuses an edit based on an out-of-date version', function () {
        $this->workflow->saveEdit($this->report, $this->v1->id, ['summary' => 'Changed elsewhere']);

        expect(fn () => $this->workflow->saveEdit($this->report->fresh(), $this->v1->id, ['summary' => 'My change']))->toThrow(StaleReportException::class)
            ->and(ReportVersion::query()->count())->toBe(2);
    });

    it('cannot edit while generating or after cancelling', function (ReportStatus $status) {
        $this->report->update(['status' => $status]);

        expect(fn () => $this->workflow->saveEdit($this->report->fresh(), $this->v1->id, ['summary' => 'x']))->toThrow(InvalidArgumentException::class);
    })->with([ReportStatus::Generating, ReportStatus::Cancelled]);
});

describe('approval', function () {
    it('approves the current version, which then cannot be changed or deleted', function () {
        $this->workflow->approve($this->report, $this->v1->id);

        $report = $this->report->fresh();
        $v = $this->v1->fresh();
        expect($report->status)->toBe(ReportStatus::Approved)->and($report->approved_at)->not->toBeNull()->and($v->approved_at)->not->toBeNull();

        expect(function () use ($v) {
            $v->content = ['title' => 'tampered'];
            $v->save();
        })->toThrow(ImmutableReportVersionException::class);
        expect(fn () => $this->v1->fresh()->delete())->toThrow(ImmutableReportVersionException::class)
            ->and($this->v1->fresh()->content['title'])->toBe('T');
    });

    it('needs a report in review and the version the person saw', function () {
        expect(fn () => $this->workflow->approve($this->report, $this->v1->id + 99))->toThrow(StaleReportException::class);

        $this->workflow->approve($this->report, $this->v1->id);
        expect(fn () => $this->workflow->approve($this->report->fresh(), $this->v1->id))->toThrow(InvalidArgumentException::class);
    });

    it('a later edit makes a new version in review and keeps the approved one intact', function () {
        $this->workflow->approve($this->report, $this->v1->id);
        $approvedAt = $this->v1->fresh()->approved_at;

        $v2 = $this->workflow->saveEdit($this->report->fresh(), $this->v1->id, ['summary' => 'Revised closing']);

        $report = $this->report->fresh();
        expect($report->status)->toBe(ReportStatus::InReview)->and($report->approved_at)->toBeNull()->and($report->current_version_id)->toBe($v2->id)
            ->and($this->v1->fresh()->approved_at->toIso8601String())->toBe($approvedAt->toIso8601String())
            ->and($this->v1->fresh()->content['sections'][1]['markdown'])->toBe('Closing text');
    });
});

describe('cancelling', function () {
    it('cancels a draft or review report and frees its period for a new one', function () {
        $this->workflow->cancel($this->report);

        expect($this->report->fresh()->status)->toBe(ReportStatus::Cancelled);
        $again = app(ReportGenerator::class)->findOrCreate($this->project, $this->report->type, $this->report->period_start->format('Y-m-d'), $this->report->period_end->format('Y-m-d'), $this->report->language);
        expect($again->id)->not->toBe($this->report->id);
    });

    it('does not cancel an approved, running or already cancelled report', function (ReportStatus $status) {
        $this->report->update(['status' => $status]);

        expect(fn () => $this->workflow->cancel($this->report->fresh()))->toThrow(InvalidArgumentException::class);
    })->with([ReportStatus::Approved, ReportStatus::Generating, ReportStatus::Cancelled]);
});

describe('regenerating', function () {
    it('keeps an approved report approved when a regeneration crashes', function () {
        $project = $this->project;
        $task = Task::factory()->for($project)->create(['title' => 'Task', 'status' => TaskStatus::InProgress]);
        Activity::factory()->for($task)->create(['activity_date' => '2026-09-10']);
        $report = app(ReportGenerator::class)->findOrCreate($project, ReportType::Monthly, '2026-09-01', '2026-09-30', Language::English);
        fakeAi()->respondWith(json_encode(['markdown' => 'No numbers here.', 'used_task_ids' => []]));
        $first = app(ReportGenerator::class)->generate($report);
        $this->workflow->approve($report->fresh(), $first->id);

        fakeAi()->failWith(new RuntimeException('boom'));
        expect(fn () => app(ReportGenerator::class)->generate($report->fresh()))->toThrow(RuntimeException::class);

        expect($report->fresh()->status)->toBe(ReportStatus::Approved)->and($report->fresh()->generation_lock_until)->toBeNull();
    });
});

describe('comparing versions', function () {
    it('shows per section what stayed, changed, was added or removed, line by line', function () {
        $v2 = ReportVersion::factory()->create([
            'report_id' => $this->report->id, 'version_no' => 2,
            'content' => ['title' => 'T', 'language' => 'en', 'sections' => [
                ['key' => 'overview', 'title' => 'Overview', 'markdown' => "Line A\nLine C\nLine D", 'fallback' => false],
                ['key' => 'summary', 'title' => 'Summary', 'markdown' => 'Closing text', 'fallback' => false],
                ['key' => 'extra', 'title' => 'Extra', 'markdown' => 'New section', 'fallback' => false],
            ]],
        ]);

        $diff = collect(app(ReportDiff::class)->compare($this->v1, $v2))->keyBy('key');

        expect($diff['overview']['state'])->toBe('changed')
            ->and(array_map(fn ($l) => $l['type'].':'.$l['text'], $diff['overview']['lines']))->toBe(['same:Line A', 'del:Line B', 'add:Line C', 'add:Line D'])
            ->and($diff['summary']['state'])->toBe('same')->and($diff['extra']['state'])->toBe('added')
            ->and(collect(app(ReportDiff::class)->compare($v2, $this->v1))->keyBy('key')['extra']['state'])->toBe('removed');
    });
});
