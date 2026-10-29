<?php

use App\Enums\Language;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\TaskPersonRole;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Person;
use App\Models\Project;
use App\Models\Report;
use App\Models\Task;
use App\Models\TaskPerson;
use App\Services\Ai\AiRequest;
use App\Services\Report\ReportFactsBuilder;
use App\Services\Report\ReportMarkdown;
use App\Services\Report\ReportRequests;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 29, 10, 0, 0, 'Asia/Jakarta')); // Thursday
    $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta', 'default_language' => 'id']);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal', 'slug' => 'harbor-portal', 'default_language' => Language::Indonesian]);
    $this->tiko = Person::factory()->create(['user_id' => $this->user->id, 'project_id' => $this->project->id, 'name' => 'Tiko']);

    $this->task = Task::factory()->for($this->project)->create([
        'title' => 'Desain Homepage',
        'status' => TaskStatus::InProgress,
        'started_at' => '2026-10-26 02:00:00+00',
    ]);
    TaskPerson::create(['task_id' => $this->task->id, 'person_id' => $this->tiko->id, 'role' => TaskPersonRole::Assignee]);

    Activity::factory()->for($this->task)->create([
        'activity_date' => '2026-10-27',
        'summary' => 'Menyelesaikan mockup homepage',
    ]);

    fakeAi()->using(function (AiRequest $r) {
        $p = json_decode($r->user, true);

        return json_encode([
            'markdown' => 'Pekerjaan minggu ini berfokus pada {{task:'.$p['tasks'][0]['id'].'}}.',
            'used_task_ids' => [$p['tasks'][0]['id']],
        ]);
    });
});

afterEach(fn () => Carbon::setTestNow());

describe('Weekly Report On-Demand (PRD Scrum §6)', function () {
    it('determines ReportType correctly based on range', function () {
        $requests = app(ReportRequests::class);

        // Monday 2026-10-26 to Sunday 2026-11-01 is 7 days
        expect($requests->typeOf('2026-10-26', '2026-11-01'))->toBe(ReportType::Weekly);

        // 1st of month to end of month
        expect($requests->typeOf('2026-10-01', '2026-10-31'))->toBe(ReportType::Monthly);

        // Other ranges
        expect($requests->typeOf('2026-10-10', '2026-10-20'))->toBe(ReportType::Custom);
    });

    it('formats weekly period label and title in Indonesian and English', function () {
        $builder = app(ReportFactsBuilder::class);

        $labelId = $builder->periodLabel(ReportType::Weekly, '2026-10-26', '2026-11-01', Language::Indonesian);
        expect($labelId)->toContain('Minggu ke-44')
            ->and($labelId)->toContain('26 Okt – 1 Nov 2026');

        $labelEn = $builder->periodLabel(ReportType::Weekly, '2026-10-26', '2026-11-01', Language::English);
        expect($labelEn)->toContain('Week 44')
            ->and($labelEn)->toContain('26 Oct – 1 Nov 2026');
    });

    it('creates weekly report on-demand from dashboard and displays assignee in detailed facts', function () {
        $requests = app(ReportRequests::class);
        $result = $requests->request($this->project, '2026-10-26', '2026-11-01', Language::Indonesian, false, 'dashboard', ReportType::Weekly);

        expect($result['status'])->toBe(ReportRequests::QUEUED)
            ->and($result['report'])->not->toBeNull()
            ->and($result['report']->type)->toBe(ReportType::Weekly);

        // Settle the queued job inline
        runWorker();
        actingAsUser($this->user);

        $report = Report::query()->find($result['report']->id);
        expect($report->status)->toBe(ReportStatus::InReview)
            ->and($report->currentVersion)->not->toBeNull();

        $markdown = app(ReportMarkdown::class)->build($report->currentVersion);
        expect($markdown)->toContain('Laporan Mingguan Harbor Portal')
            ->and($markdown)->toContain('Tiko') // Assignee must appear
            ->and($markdown)->toContain('Desain Homepage');
    });

    it('triggers weekly report on-demand via Telegram /report weekly', function () {
        send('/report weekly', 10);

        $report = Report::query()->where('type', ReportType::Weekly)->first();
        expect($report)->not->toBeNull()
            ->and($report->period_start->format('Y-m-d'))->toBe('2026-10-26')
            ->and($report->period_end->format('Y-m-d'))->toBe('2026-11-01')
            ->and($report->status)->toBe(ReportStatus::InReview);

        $sentTexts = array_column(fakeTelegram()->sent, 'text');
        expect(collect($sentTexts)->contains(fn ($t) => str_contains($t, 'Laporan Mingguan Harbor Portal')))->toBeTrue();
    });
});
