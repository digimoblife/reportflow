<?php

use App\Enums\ActivitySource;
use App\Enums\ActivityType;
use App\Enums\Language;
use App\Enums\ReportCreatedBy;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\ReportVersion;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Report\InstructionResult;
use App\Services\Report\ReportEditor;
use App\Services\Report\ReportGenerator;
use App\Services\Report\ReportWorkflow;
use App\Services\Report\StaleReportException;
use Illuminate\Support\Carbon;
use Tests\Support\FakeSecrets;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 1, 9, 0, 0, 'Asia/Jakarta'));
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta']);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal', 'slug' => 'harbor-portal']);
    $this->tracking = Task::factory()->for($this->project)->create(['title' => 'Shipment Tracking API', 'status' => TaskStatus::InProgress, 'started_at' => '2026-09-02 02:00:00+00']);
    $this->other = Task::factory()->for($this->project)->create(['title' => 'Invoice Export', 'status' => TaskStatus::InProgress, 'started_at' => '2026-09-05 02:00:00+00']);
    Activity::factory()->for($this->tracking)->create(['activity_date' => '2026-09-03', 'summary' => 'Webhook receiver written']);
    Activity::factory()->for($this->other)->create(['activity_date' => '2026-09-20', 'summary' => 'Export finished']);

    // every section's narrative: a plain sentence citing the first task
    $this->narrative = fn (array $p, string $extra = '') => json_encode(['markdown' => 'Work centred on {{task:'.$p['tasks'][0]['id'].'}}.'.$extra, 'used_task_ids' => [$p['tasks'][0]['id']]]);
    fakeAi()->using(fn (AiRequest $r) => ($this->narrative)(json_decode($r->user, true)));

    $this->generator = app(ReportGenerator::class);
    $this->report = $this->generator->findOrCreate($this->project, ReportType::Monthly, '2026-09-01', '2026-09-30', Language::English);
    $this->v1 = $this->generator->generate($this->report);
    $this->editor = app(ReportEditor::class);
    fakeAi()->requests = [];
});

afterEach(fn () => Carbon::setTestNow());

/** Script the two AI steps: what the model finds in the instruction, and how it rewrites the section. */
function scriptAi(array $facts = [], array $unmatched = [], ?Closure $rewrite = null): void
{
    fakeAi()->using(function (AiRequest $r) use ($facts, $unmatched, $rewrite) {
        $payload = json_decode($r->user, true);

        if ($r->purpose === 'report_instruction') {
            return json_encode(['new_facts' => $facts, 'unmatched_facts' => $unmatched]);
        }

        return $rewrite ? $rewrite($payload) : json_encode(['markdown' => 'Work centred on {{task:'.$payload['tasks'][0]['id'].'}}.', 'used_task_ids' => [$payload['tasks'][0]['id']]]);
    });
}

function sectionText(ReportVersion $v, string $key): string
{
    return collect($v->content['sections'])->firstWhere('key', $key)['markdown'];
}

describe('a new fact in the instruction', function () {
    it('is saved as an activity first, then the section is rewritten, as one new version', function () {
        scriptAi([['task_id' => test()->tracking->id, 'summary' => 'The API outage lasted 25 minutes.', 'date' => '2026-09-14', 'activity_type' => 'blocker']]);

        $result = $this->editor->instruct($this->report, $this->v1->id, 'incidents', 'Add the downtime detail: the API outage lasted 25 minutes on 14 September.');

        $activity = Activity::query()->where('source', ActivitySource::ReportEdit)->sole();
        $version = $result->version;
        expect($result->ok())->toBeTrue()->and($result->factsSaved)->toBe(1)
            ->and($activity->task_id)->toBe($this->tracking->id)->and($activity->activity_date->format('Y-m-d'))->toBe('2026-09-14')
            ->and($activity->activity_type)->toBe(ActivityType::Blocker)->and($activity->summary)->toBe('The API outage lasted 25 minutes.')
            ->and($version->version_no)->toBe(2)->and($version->created_by)->toBe(ReportCreatedBy::InstructionEdit)
            ->and($version->instruction)->toContain('downtime detail')
            ->and($version->source_activity_ids)->toContain($activity->id)->and($version->source_activity_ids)->toHaveCount(3)
            ->and(sectionText($version, 'incidents'))->toContain('The API outage lasted 25 minutes.')->toContain('14 Sep 2026')
            ->and(sectionText($version, 'overview'))->toBe(sectionText($this->v1, 'overview'))   // other sections untouched
            ->and($version->content['counts']['incidents'])->toBe(1)
            ->and($this->report->fresh()->current_version_id)->toBe($version->id)->and($this->v1->fresh()->version_no)->toBe(1);
        expect($this->tracking->fresh()->last_activity_at->setTimezone('Asia/Jakarta')->format('Y-m-d'))->toBe('2026-09-14');
    });

    it('is not written when the instruction names a fact that fits no task of the report', function () {
        scriptAi([], ['A server move in August happened']);

        $result = $this->editor->instruct($this->report, $this->v1->id, 'incidents', 'Mention the server move.');

        expect($result->status)->toBe(InstructionResult::UNMATCHED)->and($result->unmatched)->toBe(['A server move in August happened'])
            ->and(ReportVersion::query()->count())->toBe(1)->and(Activity::query()->where('source', ActivitySource::ReportEdit)->count())->toBe(0)
            ->and(collect(fakeAi()->requests)->pluck('purpose')->all())->toBe(['report_instruction']);   // no rewrite was attempted
    });

    it('rejects facts for tasks outside the report, dates outside the period and future dates', function (array $fact) {
        scriptAi([$fact]);

        $result = $this->editor->instruct($this->report, $this->v1->id, 'incidents', 'Add this.');

        expect($result->status)->toBe(InstructionResult::AI_FAILED)->and(Activity::query()->where('source', ActivitySource::ReportEdit)->count())->toBe(0)->and(ReportVersion::query()->count())->toBe(1);
    })->with([
        'foreign task' => [fn () => ['task_id' => 999999, 'summary' => 'Some event happened', 'date' => '2026-09-14', 'activity_type' => 'blocker']],
        'before the period' => [fn () => ['task_id' => test()->tracking->id, 'summary' => 'Some event happened', 'date' => '2026-08-31', 'activity_type' => 'blocker']],
        'after the period' => [fn () => ['task_id' => test()->tracking->id, 'summary' => 'Some event happened', 'date' => '2026-10-02', 'activity_type' => 'blocker']],
        'not a date' => [fn () => ['task_id' => test()->tracking->id, 'summary' => 'Some event happened', 'date' => '2026-02-30', 'activity_type' => 'blocker']],
        'unknown type' => [fn () => ['task_id' => test()->tracking->id, 'summary' => 'Some event happened', 'date' => '2026-09-14', 'activity_type' => 'party']],
    ]);

    it('stores nothing when the rewrite fails, and nothing when the report changed while the model worked', function () {
        // rewrite states a number the data does not contain -> fallback -> refused
        scriptAi([], [], fn ($p) => json_encode(['markdown' => 'We handled 77 requests.', 'used_task_ids' => []]));
        $failed = $this->editor->instruct($this->report, $this->v1->id, 'overview', 'Say we handled 77 requests.');
        expect($failed->status)->toBe(InstructionResult::REWRITE_FAILED)->and(ReportVersion::query()->count())->toBe(1);

        // a colleague saves a new version while the model is rewriting: the whole edit (facts included) is rolled back
        scriptAi([['task_id' => $this->tracking->id, 'summary' => 'The outage lasted 25 minutes.', 'date' => '2026-09-14', 'activity_type' => 'blocker']], [], function ($p) {
            app(ReportWorkflow::class)->saveEdit($this->report->fresh(), $this->v1->id, ['summary' => 'Edited meanwhile']);

            return json_encode(['markdown' => 'Work centred on {{task:'.$p['tasks'][0]['id'].'}}.', 'used_task_ids' => [$p['tasks'][0]['id']]]);
        });

        expect(fn () => $this->editor->instruct($this->report, $this->v1->id, 'overview', 'Add the outage.'))->toThrow(StaleReportException::class)
            ->and(Activity::query()->where('source', ActivitySource::ReportEdit)->count())->toBe(0)->and(ReportVersion::query()->count())->toBe(2);
    });
});

describe('an instruction about wording', function () {
    it('rewrites a narrative section from the same data and records no activity', function () {
        $seen = [];
        scriptAi([], [], function ($p) use (&$seen) {
            $seen = $p;

            return json_encode(['markdown' => 'Shorter: {{task:'.$p['tasks'][0]['id'].'}}.', 'used_task_ids' => [$p['tasks'][0]['id']]]);
        });

        $result = $this->editor->instruct($this->report, $this->v1->id, 'overview', 'Make the overview shorter.');

        expect($result->ok())->toBeTrue()->and($result->factsSaved)->toBe(0)->and($seen['instruction'])->toBe('Make the overview shorter.')
            ->and(sectionText($result->version, 'overview'))->toStartWith('Shorter: **')
            ->and(Activity::query()->where('source', ActivitySource::ReportEdit)->count())->toBe(0)
            ->and($result->version->source_activity_ids)->toBe($this->v1->source_activity_ids);
    });

    it('has nothing to do for a facts-only section without a new fact', function () {
        scriptAi([]);

        expect($this->editor->instruct($this->report, $this->v1->id, 'completed', 'Make it prettier.')->status)->toBe(InstructionResult::NOTHING_TO_CHANGE)
            ->and(ReportVersion::query()->count())->toBe(1);
    });
});

describe('guards', function () {
    it('refuses an out-of-date version before asking the model anything', function () {
        app(ReportWorkflow::class)->saveEdit($this->report, $this->v1->id, ['summary' => 'x']);

        expect(fn () => $this->editor->instruct($this->report->fresh(), $this->v1->id, 'overview', 'Shorter.'))->toThrow(StaleReportException::class)
            ->and(fakeAi()->requests)->toBe([]);
    });

    it('rejects empty, oversized and unknown-section instructions without any AI call', function (string $section, string $text) {
        expect($this->editor->instruct($this->report, $this->v1->id, $section, $text)->status)->toBe(InstructionResult::INVALID)->and(fakeAi()->requests)->toBe([]);
    })->with([['overview', '   '], ['overview', 'x'], ['nonsense', 'Shorter please.']]);

    it('never lets a credential reach the model or the stored instruction', function () {
        $key = FakeSecrets::openAiKey();
        scriptAi([]);

        $result = $this->editor->instruct($this->report, $this->v1->id, 'overview', "Shorter, and the key is {$key}.");

        expect($result->ok())->toBeTrue()->and($result->version->instruction)->not->toContain($key)
            ->and(json_encode(array_map(fn ($r) => $r->user, fakeAi()->requests)))->not->toContain($key);
    });

    it('fails closed when the instruction cannot be checked', function () {
        config(['redaction.max_input_length' => 20]);
        scriptAi([]);

        expect($this->editor->instruct($this->report, $this->v1->id, 'overview', str_repeat('Please make it shorter. ', 5))->status)->toBe(InstructionResult::REDACTION_FAILED)
            ->and(fakeAi()->requests)->toBe([]);
    });

    it('reports an unavailable model without writing anything', function () {
        fakeAi()->failWith(new AiProviderException('down'));

        expect($this->editor->instruct($this->report, $this->v1->id, 'overview', 'Shorter please.')->status)->toBe(InstructionResult::AI_FAILED)->and(ReportVersion::query()->count())->toBe(1);
    });

    it('leaves an approved version intact: the edit becomes a new version in review', function () {
        app(ReportWorkflow::class)->approve($this->report, $this->v1->id);
        scriptAi([]);

        $result = $this->editor->instruct($this->report->fresh(), $this->v1->id, 'overview', 'Shorter please.');

        expect($result->ok())->toBeTrue()->and($this->report->fresh()->status)->toBe(ReportStatus::InReview)->and($this->v1->fresh()->approved_at)->not->toBeNull();
    });
});

describe('manual edits', function () {
    it('flags sections where the person typed new numbers, as a hint to save them as activities', function () {
        $v2 = app(ReportWorkflow::class)->saveEdit($this->report, $this->v1->id, ['overview' => sectionText($this->v1, 'overview')."\n\nThe outage lasted 25 minutes.", 'summary' => sectionText($this->v1, 'summary').' Reworded.']);

        expect($this->editor->factCandidates($this->v1, $v2))->toBe(['overview']);
    });

    it('saves a fact the person wrote as an activity of the report period', function () {
        $result = $this->editor->saveFact($this->report, $this->tracking->id, '2026-09-14', 'The outage lasted 25 minutes.', ActivityType::Blocker);

        $activity = Activity::query()->where('source', ActivitySource::ReportEdit)->sole();
        expect($result->ok())->toBeTrue()->and($activity->summary)->toBe('The outage lasted 25 minutes.')->and($activity->activity_date->format('Y-m-d'))->toBe('2026-09-14')
            ->and($activity->task_id)->toBe($this->tracking->id);
    });

    it('refuses facts for other tasks, outside the period, in the future, too short, or that cannot be checked', function (int $task, string $date, string $summary) {
        $taskId = $task === 0 ? test()->tracking->id : $task;

        expect($this->editor->saveFact($this->report, $taskId, $date, $summary, ActivityType::Other)->status)->toBe(InstructionResult::INVALID)
            ->and(Activity::query()->where('source', ActivitySource::ReportEdit)->count())->toBe(0);
    })->with([
        'foreign task' => [999999, '2026-09-14', 'Something happened'],
        'before period' => [0, '2026-08-31', 'Something happened'],
        'after period' => [0, '2026-10-01', 'Something happened'],
        'too short' => [0, '2026-09-14', 'ab'],
    ]);

    it('stores no credential from a fact', function () {
        $key = FakeSecrets::openAiKey();
        $this->editor->saveFact($this->report, $this->tracking->id, '2026-09-14', "Configured the client with {$key} today", ActivityType::Configuration);

        expect(Activity::query()->where('source', ActivitySource::ReportEdit)->sole()->summary)->not->toContain($key);
    });
});
