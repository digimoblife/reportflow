<?php

use App\Enums\ActivityType;
use App\Enums\Language;
use App\Enums\ReportType;
use App\Enums\TaskStatus;
use App\Enums\WaitingReason;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Report\ReportDataSelector;
use App\Services\Report\ReportFactsBuilder;
use App\Services\Report\ReportTemplates;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create(['timezone' => 'Asia/Jakarta']);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal', 'slug' => 'harbor-portal']);
    $kedai = Project::factory()->create(['name' => 'Kedai App', 'slug' => 'kedai-app']);
    $this->w = [
        'harbor' => $this->project,
        'tracking' => Task::factory()->for($this->project)->create(['title' => 'Shipment Tracking API', 'status' => TaskStatus::InProgress]),
        'invoice' => Task::factory()->for($this->project)->create(['title' => 'Invoice PDF Export Bug', 'status' => TaskStatus::Open]),
        'menu' => Task::factory()->for($kedai)->create(['title' => 'Menu Sync', 'status' => TaskStatus::InProgress]),
    ];
    $this->selector = app(ReportDataSelector::class);
});

function activityOn(Task $task, string $date, string $summary = 'Did something', ActivityType $type = ActivityType::Development): Activity
{
    return Activity::factory()->for($task)->create(['activity_date' => $date, 'summary' => $summary, 'activity_type' => $type]);
}

function pick(string $start = '2026-09-01', string $end = '2026-09-30', string $tz = 'Asia/Jakarta')
{
    return test()->selector->select(test()->project, $start, $end, $tz);
}

describe('which activities belong to a period (PRD §45)', function () {
    it('takes the first and last day of the period, and nothing around them', function () {
        $task = $this->w['tracking'];
        $before = activityOn($task, '2026-08-31', 'before');
        $first = activityOn($task, '2026-09-01', 'first');
        $last = activityOn($task, '2026-09-30', 'last');
        $after = activityOn($task, '2026-10-01', 'after');

        $data = pick();

        expect($data->sourceActivityIds())->toBe([$first->id, $last->id])
            ->and($data->sourceActivityIds())->not->toContain($before->id, $after->id);
    });

    it('leaves out deleted activities, other projects and other users', function () {
        $kept = activityOn($this->w['tracking'], '2026-09-10');
        activityOn($this->w['tracking'], '2026-09-11')->delete();
        activityOn($this->w['menu'], '2026-09-12');   // another project of the same user
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        asUser($other->id, fn () => Activity::factory()->for(Task::factory()->for(Project::factory()->create(['user_id' => $other->id]))->create())->create(['activity_date' => '2026-09-13']));

        expect(pick()->sourceActivityIds())->toBe([$kept->id]);
    });

    it('orders activities by date then id, whatever order they were written in', function () {
        $late = activityOn($this->w['tracking'], '2026-09-20');
        $early = activityOn($this->w['invoice'], '2026-09-02');
        $same = activityOn($this->w['invoice'], '2026-09-20');

        expect(pick()->sourceActivityIds())->toBe([$early->id, $late->id, $same->id]);
    });
});

describe('how tasks are grouped', function () {
    it('separates completed, ongoing, waiting, cross-month and incidents', function () {
        $done = Task::factory()->for($this->project)->create(['title' => 'Done in period', 'status' => TaskStatus::Completed, 'completed_at' => '2026-09-18 05:00:00+00', 'started_at' => '2026-09-02 05:00:00+00']);
        Task::factory()->for($this->project)->create(['title' => 'Done without notes', 'status' => TaskStatus::Completed, 'completed_at' => '2026-09-19 05:00:00+00']);
        $doneEarlier = Task::factory()->for($this->project)->create(['title' => 'Done in August', 'status' => TaskStatus::Completed, 'completed_at' => '2026-08-20 05:00:00+00']);
        $carried = Task::factory()->for($this->project)->create(['title' => 'Carried over', 'status' => TaskStatus::InProgress, 'started_at' => '2026-08-15 05:00:00+00']);
        $waiting = Task::factory()->for($this->project)->create(['title' => 'Waiting task', 'status' => TaskStatus::Waiting, 'waiting_reason' => WaitingReason::Client]);
        $cancelled = Task::factory()->for($this->project)->create(['title' => 'Dropped', 'status' => TaskStatus::Cancelled]);
        $draft = Task::factory()->for($this->project)->create(['title' => 'Unconfirmed', 'status' => TaskStatus::Draft]);
        Task::factory()->for($this->project)->create(['title' => 'No activity at all', 'status' => TaskStatus::InProgress]);

        foreach ([$done, $doneEarlier, $carried, $waiting, $cancelled, $draft] as $t) {
            activityOn($t, '2026-09-15');
        }
        $incident = activityOn($this->w['tracking'], '2026-09-16', 'API timeout', ActivityType::Blocker);

        $data = pick();
        $titles = fn (array $ids) => array_map(fn ($id) => $data->tasks[$id]['title'], $ids);

        expect($titles($data->completedTaskIds))->toBe(['Done in period', 'Done without notes'])
            ->and($titles($data->ongoingTaskIds))->toContain('Carried over', 'Shipment Tracking API')->not->toContain('No activity at all')
            ->and($titles($data->waitingTaskIds))->toBe(['Waiting task'])
            ->and($titles($data->crossMonthTaskIds))->toBe(['Carried over'])
            ->and($data->incidentActivityIds)->toBe([$incident->id])
            ->and(array_column($data->tasks, 'title'))->not->toContain('Dropped', 'Unconfirmed')
            ->and($data->counts())->toMatchArray(['completed' => 2, 'waiting' => 1, 'cross_month' => 1, 'incidents' => 1]);
    });

    it('treats a task whose first activity predates the period as cross-month even without started_at', function () {
        $task = Task::factory()->for($this->project)->create(['title' => 'Old news', 'status' => TaskStatus::InProgress, 'started_at' => null]);
        activityOn($task, '2026-08-28');
        activityOn($task, '2026-09-05');

        $data = pick();

        expect(array_map(fn ($id) => $data->tasks[$id]['title'], $data->crossMonthTaskIds))->toBe(['Old news']);
    });

    it('reads the period in the user\'s calendar, not UTC', function () {
        // 2026-08-31 23:00 UTC is already 1 September at 13:00 in Pacific/Kiritimati (UTC+14), but still 31 August in UTC.
        $task = Task::factory()->for($this->project)->create(['title' => 'Boundary', 'status' => TaskStatus::Completed, 'completed_at' => '2026-08-31 23:00:00+00']);

        expect(pick(tz: 'Pacific/Kiritimati')->completedTaskIds)->toBe([$task->id])
            ->and(pick(tz: 'UTC')->completedTaskIds)->toBe([]);
    });
});

describe('the snapshot', function () {
    it('is the same for the same data, whenever it is taken', function () {
        activityOn($this->w['tracking'], '2026-09-10');

        $a = $this->selector->select($this->project, '2026-09-01', '2026-09-30', 'Asia/Jakarta', CarbonImmutable::parse('2026-10-01 00:00:00'));
        $b = $this->selector->select($this->project, '2026-09-01', '2026-09-30', 'Asia/Jakarta', CarbonImmutable::parse('2026-10-05 00:00:00'));

        expect($a->activities)->toBe($b->activities)->and($a->tasks)->toBe($b->tasks);
    });

    it('stays frozen to the version\'s activity ids: later additions and deletions do not drift in', function () {
        $kept = activityOn($this->w['tracking'], '2026-09-10', 'kept');
        $removedLater = activityOn($this->w['tracking'], '2026-09-11', 'removed later');
        $frozen = pick()->sourceActivityIds();

        $removedLater->delete();
        $addedLater = activityOn($this->w['tracking'], '2026-09-12', 'added later');

        $again = $this->selector->select($this->project, '2026-09-01', '2026-09-30', 'Asia/Jakarta', null, $frozen);

        expect($again->sourceActivityIds())->toBe([$kept->id, $removedLater->id])->and($again->sourceActivityIds())->not->toContain($addedLater->id);
    });
});

describe('facts (deterministic Markdown)', function () {
    beforeEach(function () {
        $this->facts = app(ReportFactsBuilder::class);
        $done = Task::factory()->for($this->project)->create(['title' => 'Fix | the *pipe* [x]', 'status' => TaskStatus::Completed, 'completed_at' => '2026-09-18 05:00:00+00']);
        activityOn($done, '2026-09-17', "Line one\nline <b>two</b> | pipe");
        activityOn($this->w['tracking'], '2026-09-20', 'Webhook live');
        $this->data = pick();
    });

    it('states the counts of the data and nothing else', function () {
        $overview = $this->facts->facts('overview', $this->data, ReportType::Monthly, Language::English);

        expect($overview)->toContain('- **Activities:** 2')->toContain('- **Completed tasks:** 1')->toContain('- **Period:** September 2026')->toContain('- **Incidents / issues:** 0');
    });

    it('escapes what people wrote so a title cannot change the document', function () {
        $completed = $this->facts->facts('completed', $this->data, ReportType::Monthly, Language::English);
        $detailed = $this->facts->facts('detailed', $this->data, ReportType::Monthly, Language::English);

        expect($completed)->toContain('Fix \\| the \\*pipe\\* \\[x\\]')->not->toContain('| the *pipe*')
            ->and(explode("\n", $completed))->toHaveCount(3)   // header, separator, one row
            ->and($detailed)->toContain('line \\<b\\>two\\</b\\> \\| pipe')->not->toContain("\nline");
    });

    it('speaks the report language, formally', function () {
        $id = $this->facts->facts('completed', $this->data, ReportType::Monthly, Language::Indonesian);

        expect($id)->toContain('Selesai pada')->and($this->facts->facts('overview', $this->data, ReportType::Monthly, Language::Indonesian))->toContain('**Task selesai:** 1');
    });

    it('says so when a section has nothing', function () {
        $empty = pick('2026-01-01', '2026-01-31');

        expect($this->facts->facts('incidents', $empty, ReportType::Monthly, Language::English))->toBe('None.')
            ->and($this->facts->facts('cross_month', $empty, ReportType::Monthly, Language::Indonesian))->toBe('Tidak ada.');
    });

    it('labels periods: a whole month by name, anything else as a range', function () {
        expect($this->facts->periodLabel(ReportType::Monthly, '2026-09-01', '2026-09-30', Language::English))->toBe('September 2026')
            ->and($this->facts->periodLabel(ReportType::Custom, '2026-09-15', '2026-09-30', Language::English))->toBe('15 Sep 2026 – 30 Sep 2026')
            ->and($this->facts->periodLabel(ReportType::Monthly, '2026-09-05', '2026-09-30', Language::English))->toBe('5 Sep 2026 – 30 Sep 2026')
            ->and($this->facts->title($this->data, ReportType::Monthly, Language::Indonesian))->toBe('Laporan Bulanan Harbor Portal — September 2026');
    });

    it('has a number-only fallback sentence for each narrative section', function () {
        foreach (['overview', 'detailed', 'ongoing', 'summary'] as $section) {
            expect($this->facts->fallbackNarrative($section, $this->data, ReportType::Monthly, Language::English))->not->toBe('');
        }

        expect($this->facts->fallbackNarrative('overview', $this->data, ReportType::Monthly, Language::English))->toContain('2 activities were recorded for Harbor Portal');
    });
});

describe('templates', function () {
    it('creates one generic template per language from config, with localized titles', function () {
        $templates = app(ReportTemplates::class);

        $en = $templates->forLanguage(Language::English);
        $again = $templates->forLanguage(Language::English);
        $id = $templates->forLanguage(Language::Indonesian);

        expect($again->id)->toBe($en->id)->and($id->id)->not->toBe($en->id)
            ->and(array_column($en->sections, 'key'))->toBe(['overview', 'completed', 'detailed', 'ongoing', 'cross_month', 'incidents', 'summary'])
            ->and($en->sections[0]['title'])->toBe('Monthly Overview')->and($id->sections[0]['title'])->toBe('Ringkasan Bulanan')
            ->and(array_column($en->sections, 'narrative'))->toBe([true, false, true, true, false, false, true])
            ->and($en->blade_view)->toBe('reports.templates.generic');
    });
});

it('keeps the report language files in step: same keys and placeholders', function () {
    $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
        $out = [];
        foreach ($array as $key => $value) {
            is_array($value) ? $out += $flatten($value, "{$prefix}{$key}.") : $out["{$prefix}{$key}"] = $value;
        }

        return $out;
    };
    $id = $flatten(require lang_path('id/report.php'));
    $en = $flatten(require lang_path('en/report.php'));
    $placeholders = function (string $s): array {
        preg_match_all('/:[a-z_]+/', $s, $m);
        sort($m[0]);

        return $m[0];
    };

    expect(array_keys($id))->toBe(array_keys($en));

    foreach ($id as $key => $value) {
        expect($placeholders($value))->toBe($placeholders($en[$key]), $key);
    }
});
