<?php

use App\Enums\ActivityType;
use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\ReportCreatedBy;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\TaskStatus;
use App\Jobs\GenerateReport;
use App\Models\Activity;
use App\Models\AiInteraction;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\ReportVersion;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Report\ReportGenerator;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 1, 9, 0, 0, 'Asia/Jakarta'));
    $this->user = User::factory()->create(['timezone' => 'Asia/Jakarta', 'telegram_user_id' => 555001]);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal', 'slug' => 'harbor-portal']);
    $this->tracking = Task::factory()->for($this->project)->create(['title' => 'Shipment Tracking API', 'status' => TaskStatus::InProgress, 'started_at' => '2026-09-02 02:00:00+00']);
    $this->invoice = Task::factory()->for($this->project)->create(['title' => 'Invoice Export', 'status' => TaskStatus::Completed, 'completed_at' => '2026-09-20 02:00:00+00', 'started_at' => '2026-09-05 02:00:00+00']);
    foreach ([[$this->tracking, '2026-09-03', 'Webhook receiver written'], [$this->tracking, '2026-09-15', 'Retry logic added'], [$this->invoice, '2026-09-20', 'Export finished']] as [$task, $date, $summary]) {
        Activity::factory()->for($task)->create(['activity_date' => $date, 'summary' => $summary, 'activity_type' => ActivityType::Development]);
    }
    $this->generator = app(ReportGenerator::class);
    $this->report = $this->generator->findOrCreate($this->project, ReportType::Monthly, '2026-09-01', '2026-09-30', Language::English);
});

afterEach(fn () => Carbon::setTestNow());

/** A well-behaved narrative for whatever section the request asks about. */
function goodNarrative(AiRequest $request): string
{
    $payload = json_decode($request->user, true);
    $ids = array_slice(array_column($payload['tasks'], 'id'), 0, 2);
    $tokens = implode(' and ', array_map(fn ($id) => '{{task:'.$id.'}}', $ids));

    return json_encode(['markdown' => "Work in the period centred on {$tokens}. {$payload['counts']['completed']} tasks were completed.", 'used_task_ids' => $ids]);
}

function sectionOf(ReportVersion $version, string $key): array
{
    return collect($version->content['sections'])->firstWhere('key', $key);
}

describe('a generated version', function () {
    it('has the seven sections of the template, a title, counts and the frozen source ids', function () {
        fakeAi()->using(fn (AiRequest $r) => goodNarrative($r));

        $version = $this->generator->generate($this->report);

        $report = $this->report->fresh();
        expect(array_column($version->content['sections'], 'key'))->toBe(['overview', 'completed', 'detailed', 'ongoing', 'cross_month', 'incidents', 'summary'])
            ->and($version->content['title'])->toBe('Harbor Portal Monthly Report — September 2026')
            ->and($version->content['counts'])->toMatchArray(['activities' => 3, 'completed' => 1])
            ->and($version->version_no)->toBe(1)->and($version->created_by)->toBe(ReportCreatedBy::AiGenerate)
            ->and($version->source_activity_ids)->toHaveCount(3)
            ->and($version->data_snapshot_at->toIso8601String())->toBe(now('UTC')->toIso8601String())
            ->and($report->status)->toBe(ReportStatus::InReview)->and($report->current_version_id)->toBe($version->id)->and($report->generation_lock_until)->toBeNull();
    });

    it('puts the narrative first, then the deterministic facts, with real task titles', function () {
        fakeAi()->using(fn (AiRequest $r) => goodNarrative($r));

        $overview = sectionOf($this->generator->generate($this->report), 'overview');

        expect($overview['fallback'])->toBeFalse()
            ->and($overview['markdown'])->toStartWith('Work in the period centred on **')
            ->and($overview['markdown'])->toContain('**Invoice Export**')->not->toContain('{{task:')
            ->and($overview['markdown'])->toContain('- **Activities:** 3');
    });

    it('asks the model once per narrative section, shows it only that section\'s data, and records every call', function () {
        fakeAi()->using(fn (AiRequest $r) => goodNarrative($r));
        $this->generator->generate($this->report);

        $requests = fakeAi()->requests;
        $sections = array_map(fn ($r) => json_decode($r->user, true)['section']['key'], $requests);
        $ongoing = json_decode($requests[array_search('ongoing', $sections)]->user, true);

        expect($sections)->toBe(['overview', 'detailed', 'ongoing', 'summary'])
            ->and(array_column($ongoing['tasks'], 'title'))->toBe(['Shipment Tracking API'])   // only the ongoing task, not the completed one
            ->and(AiInteraction::query()->where('purpose', 'report_section')->count())->toBe(4)
            ->and(AiInteraction::query()->where('purpose', 'report_section')->first()->prompt_version)->toBe('report_section@v1')
            ->and(AiInteraction::query()->where('purpose', 'report_section')->first()->report_id)->toBe($this->report->id);
    });

    it('queues the PDF and Markdown of the new version, which are made together', function () {
        fakeAi()->using(fn (AiRequest $r) => goodNarrative($r));

        $version = $this->generator->generate($this->report);

        expect(ReportFile::query()->where('report_version_id', $version->id)->pluck('format')->map(fn ($f) => $f->value)->sort()->values()->all())->toBe(['md', 'pdf']);
    });

    it('creates the next version from a fresh snapshot and leaves the old one untouched', function () {
        fakeAi()->using(fn (AiRequest $r) => goodNarrative($r));
        $first = $this->generator->generate($this->report);
        $contentBefore = $first->content;

        Activity::factory()->for($this->tracking)->create(['activity_date' => '2026-09-25', 'summary' => 'Late note']);
        $second = $this->generator->generate($this->report->fresh());

        expect($second->version_no)->toBe(2)->and($second->source_activity_ids)->toHaveCount(4)
            ->and($first->fresh()->content)->toEqual($contentBefore)->and($first->fresh()->source_activity_ids)->toHaveCount(3)
            ->and($this->report->fresh()->current_version_id)->toBe($second->id);
    });
});

describe('traceability', function () {
    it('rejects a narrative that cites a task which is not in the section data, and falls back', function () {
        $stranger = Task::factory()->for(Project::factory()->create())->create(['title' => 'Not in this report']);
        fakeAi()->respondWith(json_encode(['markdown' => 'Focus was on {{task:'.$stranger->id.'}}.', 'used_task_ids' => [$stranger->id]]));

        $overview = sectionOf($this->generator->generate($this->report), 'overview');

        expect($overview['fallback'])->toBeTrue()->and($overview['markdown'])->toContain('During September 2026, 3 activities were recorded for Harbor Portal')
            ->and($overview['markdown'])->not->toContain('Not in this report');
        expect(AiInteraction::query()->where('success', false)->first()->error)->toContain('markdown:unknown_task_token');
    });

    it('rejects numbers that are not in the data', function (string $markdown) {
        fakeAi()->respondWith(json_encode(['markdown' => $markdown, 'used_task_ids' => []]));

        expect(sectionOf($this->generator->generate($this->report), 'summary')['fallback'])->toBeTrue();
    })->with(['an invented count' => ['Fourteen... actually 14 tasks were completed.'], 'a percentage' => ['Completion reached 87% of plan.'], 'a duration' => ['The outage lasted 25 minutes.']]);

    it('accepts numbers the data itself contains (counts, years, numbers inside activity texts)', function () {
        $this->tracking->update(['title' => 'Shipment Tracking API']);
        Activity::factory()->for($this->tracking)->create(['activity_date' => '2026-09-22', 'summary' => 'Handled 250 webhook calls per minute']);
        fakeAi()->respondWith(json_encode(['markdown' => 'In September 2026, 4 activities were recorded, among them 250 webhook calls per minute.', 'used_task_ids' => []]));

        expect(sectionOf($this->generator->generate($this->report), 'summary')['fallback'])->toBeFalse();
    });

    it('rejects structure the model may not add, and tokens it did not declare', function (string $reply) {
        fakeAi()->respondWith($reply);

        expect(sectionOf($this->generator->generate($this->report), 'overview')['fallback'])->toBeTrue();
    })->with([
        'a heading' => [json_encode(['markdown' => "# Big title\nText", 'used_task_ids' => []])],
        'a table' => [json_encode(['markdown' => "| a | b |\n| - | - |", 'used_task_ids' => []])],
        'html' => [json_encode(['markdown' => 'Text <script>x</script>', 'used_task_ids' => []])],
        'a link' => [json_encode(['markdown' => 'See https://evil.example for more', 'used_task_ids' => []])],
        'extra field' => [json_encode(['markdown' => 'Fine.', 'used_task_ids' => [], 'extra' => 1])],
        'not json' => ['I think the project went well.'],
        'undeclared token' => [json_encode(['markdown' => 'About {{task:1}}.', 'used_task_ids' => []])],
        'too long' => [json_encode(['markdown' => str_repeat('a', 1600), 'used_task_ids' => []])],
    ]);

    it('retries once with the error codes (never the rejected text), and accepts a corrected answer', function () {
        $calls = 0;
        fakeAi()->using(function (AiRequest $r) use (&$calls) {
            $payload = json_decode($r->user, true);

            if ($payload['section']['key'] !== 'summary') {
                return goodNarrative($r);
            }

            return ++$calls === 1
                ? json_encode(['markdown' => 'Secret invention: 999 tasks.', 'used_task_ids' => []])
                : json_encode(['markdown' => 'The period closed with 1 tasks completed.', 'used_task_ids' => []]);
        });

        $summary = sectionOf($this->generator->generate($this->report), 'summary');
        $retry = collect(fakeAi()->requests)->map(fn ($r) => json_decode($r->user, true))->firstWhere(fn ($p) => isset($p['previous_reply_rejected']));

        expect($summary['fallback'])->toBeFalse()->and($calls)->toBe(2)
            ->and($retry['previous_reply_rejected'])->toBe(['markdown:number_not_in_data'])
            ->and(json_encode($retry))->not->toContain('Secret invention');
    });

    it('falls back when the provider fails, and the report is still produced', function () {
        fakeAi()->failWith(new AiProviderException('boom'));

        $version = $this->generator->generate($this->report);

        expect(collect($version->content['sections'])->where('fallback', true)->pluck('key')->all())->toBe(['overview', 'detailed', 'ongoing', 'summary'])
            ->and($this->report->fresh()->status)->toBe(ReportStatus::InReview);
    });

    it('does not introduce a list that is empty', function () {
        fakeAi()->using(fn (AiRequest $r) => goodNarrative($r));
        $this->invoice->update(['status' => TaskStatus::Cancelled]);
        $this->tracking->update(['status' => TaskStatus::Cancelled]);   // nothing is ongoing or waiting any more

        $version = $this->generator->generate($this->report);

        expect(sectionOf($version, 'ongoing')['markdown'])->toBe('None.')
            ->and(collect(fakeAi()->requests)->map(fn ($r) => json_decode($r->user, true)['section']['key'])->all())->not->toContain('ongoing');
    });

    it('does not call the AI for a period without activities', function () {
        $empty = $this->generator->findOrCreate($this->project, ReportType::Monthly, '2026-01-01', '2026-01-31', Language::English);

        $version = $this->generator->generate($empty);

        expect(fakeAi()->requests)->toBe([])->and(sectionOf($version, 'incidents')['markdown'])->toBe('None.')
            ->and(sectionOf($version, 'overview')['markdown'])->toContain('0 activities were recorded');
    });

    it('never puts message text, summaries or the rejected reply in the logs', function () {
        $logs = captureLogs();
        fakeAi()->respondWith(json_encode(['markdown' => 'Confidential 999 reply text', 'used_task_ids' => []]));

        $this->generator->generate($this->report);

        $joined = json_encode($logs->getRecords() ? array_map(fn ($r) => [$r->message, $r->context], $logs->getRecords()) : []);
        expect($joined)->toContain('report.section_fallback')->not->toContain('Confidential')->not->toContain('Webhook receiver');
    });
});

describe('one generation at a time', function () {
    it('refuses a second generation while the first holds the lock, and takes over an abandoned one', function () {
        fakeAi()->using(fn (AiRequest $r) => goodNarrative($r));
        $this->report->update(['generation_lock_until' => now()->addMinutes(2), 'status' => ReportStatus::Generating]);

        expect($this->generator->generate($this->report->fresh()))->toBeNull()->and(ReportVersion::query()->count())->toBe(0);

        Carbon::setTestNow(now()->addMinutes(6));   // the lock lapsed: that worker died
        expect($this->generator->generate($this->report->fresh()))->not->toBeNull();
    });

    it('releases the lock and the previous status when generation crashes', function () {
        fakeAi()->failWith(new RuntimeException('unexpected'));
        $this->report->update(['status' => ReportStatus::InReview]);

        expect(fn () => $this->generator->generate($this->report->fresh()))->toThrow(RuntimeException::class);

        $report = $this->report->fresh();
        expect($report->generation_lock_until)->toBeNull()->and($report->status)->toBe(ReportStatus::InReview)->and(ReportVersion::query()->count())->toBe(0);
    });
});

describe('pre-generate check and waiting', function () {
    it('counts entries still being processed', function () {
        InboundMessage::factory()->create(['user_id' => $this->user->id, 'status' => InboundMessageStatus::Received]);
        InboundMessage::factory()->create(['user_id' => $this->user->id, 'status' => InboundMessageStatus::Processing]);
        InboundMessage::factory()->create(['user_id' => $this->user->id, 'status' => InboundMessageStatus::Processed]);
        InboundMessage::factory()->create(['user_id' => $this->user->id, 'status' => InboundMessageStatus::Failed]);

        expect($this->generator->pendingEntries())->toBe(2);
    });

    it('"Tunggu Selesai" holds back until the entries are done, then generates', function () {
        fakeAi()->using(fn (AiRequest $r) => goodNarrative($r));
        $pending = InboundMessage::factory()->create(['user_id' => $this->user->id, 'status' => InboundMessageStatus::Processing]);
        $until = now()->addMinutes(10)->getTimestamp();

        $job = new GenerateReport($this->report->id, $this->user->id, 'dashboard', true, $until);
        $job->withFakeQueueInteractions();
        $job->handle($this->generator);
        $job->assertReleased(15);
        expect(ReportVersion::query()->count())->toBe(0);

        $pending->update(['status' => InboundMessageStatus::Processed]);
        $job->handle($this->generator);
        expect(ReportVersion::query()->count())->toBe(1);
    });

    it('"Generate without these entries" and the wait limit both go ahead', function () {
        fakeAi()->using(fn (AiRequest $r) => goodNarrative($r));
        InboundMessage::factory()->create(['user_id' => $this->user->id, 'status' => InboundMessageStatus::Received]);

        (new GenerateReport($this->report->id, $this->user->id, 'dashboard', false))->handle($this->generator);
        expect(ReportVersion::query()->count())->toBe(1);

        Carbon::setTestNow(now()->addMinutes(11));
        (new GenerateReport($this->report->id, $this->user->id, 'dashboard', true, now()->subMinute()->getTimestamp()))->handle($this->generator);
        expect(ReportVersion::query()->count())->toBe(2);
    });

    it('does nothing for a report the user does not own', function () {
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $theirs = asUser($other->id, fn () => Report::factory()->create(['project_id' => Project::factory()->create(['user_id' => $other->id])->id]));

        (new GenerateReport($theirs->id, $this->user->id))->handle($this->generator);

        expect(ReportVersion::query()->count())->toBe(0);
    });
});

describe('finding or creating the report', function () {
    it('reuses the report of the same project, type, period and language; a cancelled one is replaced', function () {
        $again = $this->generator->findOrCreate($this->project, ReportType::Monthly, '2026-09-01', '2026-09-30', Language::English);
        expect($again->id)->toBe($this->report->id);

        $this->report->update(['status' => ReportStatus::Cancelled]);
        $fresh = $this->generator->findOrCreate($this->project, ReportType::Monthly, '2026-09-01', '2026-09-30', Language::English);
        expect($fresh->id)->not->toBe($this->report->id);

        $indonesian = $this->generator->findOrCreate($this->project, ReportType::Monthly, '2026-09-01', '2026-09-30', Language::Indonesian);
        expect($indonesian->id)->not->toBe($fresh->id);
    });

    it('refuses unusable periods', function (string $start, string $end) {
        expect(fn () => $this->generator->findOrCreate($this->project, ReportType::Custom, $start, $end, Language::English))->toThrow(InvalidArgumentException::class);
    })->with([['2026-09-30', '2026-09-01'], ['2024-01-01', '2026-01-01']]);
});
