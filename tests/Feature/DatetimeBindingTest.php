<?php

use App\Database\PostgresConnection;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Report;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Companion to TimezoneQueryTest: `date` columns, iteration helpers and the binding format itself.
// See docs/DECISIONS.md ("Binding datetime dengan offset") for the Laravel internals this relies on.

beforeEach(function () {
    actingAsUser();
    $this->project = Project::factory()->create();
    $this->task = Task::factory()->for($this->project)->create();
});

/*
| date columns hold the calendar date of the value *as written in its own zone*. Asia/Jakarta is
| UTC+7, so 00:30 Jakarta on 10 March is still 9 March in UTC: the date must stay 10 March.
*/
dataset('jakarta moments on 10 March', [
    'just after midnight' => '2026-03-10 00:30:00',
    'midday' => '2026-03-10 12:00:00',
    'just before midnight' => '2026-03-10 23:30:00',
]);

it('compares date columns by the calendar date of a Jakarta Carbon', function (string $moment) {
    $jakarta = CarbonImmutable::parse($moment, 'Asia/Jakarta');
    $day = fn (string $date) => Activity::factory()->for($this->task)->create(['activity_date' => $date]);
    $day('2026-03-09');
    $day('2026-03-10');
    $day('2026-03-11');

    $dates = fn ($query) => $query->orderBy('activity_date')->pluck('activity_date')->map->toDateString()->all();

    expect($dates(Activity::where('activity_date', $jakarta)))->toBe(['2026-03-10'])
        ->and($dates(Activity::whereDate('activity_date', $jakarta)))->toBe(['2026-03-10'])
        ->and($dates(Activity::whereDate('activity_date', '>=', $jakarta)))->toBe(['2026-03-10', '2026-03-11'])
        ->and($dates(Activity::whereBetween('activity_date', [$jakarta->startOfDay(), $jakarta->endOfDay()])))->toBe(['2026-03-10'])
        ->and($dates(Activity::whereBetween('activity_date', [$jakarta->subDay(), $jakarta])))->toBe(['2026-03-09', '2026-03-10'])
        ->and(DB::table('activities')->where('activity_date', $jakarta)->count())->toBe(1)
        ->and(DB::table('activities')->whereDate('activity_date', $jakarta)->count())->toBe(1)
        ->and(DB::table('activities')->whereBetween('activity_date', [$jakarta->startOfDay(), $jakarta->endOfDay()])->count())->toBe(1);
})->with('jakarta moments on 10 March');

it('writes the Jakarta calendar date to date columns on insert and update', function (string $moment) {
    $jakarta = CarbonImmutable::parse($moment, 'Asia/Jakarta');
    $raw = fn (int $id) => DB::table('activities')->where('id', $id)->value('activity_date');

    // Eloquent insert and update.
    $activity = Activity::factory()->for($this->task)->create(['activity_date' => $jakarta]);
    expect($raw($activity->id))->toBe('2026-03-10');

    $activity->update(['activity_date' => $jakarta->addDay()]);
    expect($raw($activity->id))->toBe('2026-03-11');

    // Query builder insert and update.
    $id = DB::table('activities')->insertGetId([
        'task_id' => $this->task->id,
        'project_id' => $this->project->id,
        'activity_type' => 'other',
        'summary' => 'builder insert',
        'content_structured' => '{}',
        'activity_date' => $jakarta,
        'date_precision' => 'day',
        'source' => 'manual',
        'created_at' => $jakarta,
        'updated_at' => $jakarta,
    ]);
    expect($raw($id))->toBe('2026-03-10');

    DB::table('activities')->where('id', $id)->update(['activity_date' => $jakarta->addDays(2)]);
    expect($raw($id))->toBe('2026-03-12');

    // Report period columns (date) through the model and the builder.
    $report = Report::factory()->for($this->project)->create([
        'period_start' => $jakarta->startOfMonth(),
        'period_end' => $jakarta->endOfMonth(),
    ]);
    expect(DB::table('reports')->where('id', $report->id)->first(['period_start', 'period_end']))
        ->period_start->toBe('2026-03-01')
        ->period_end->toBe('2026-03-31');

    expect(Report::where('period_start', '<=', $jakarta)->where('period_end', '>=', $jakarta)->count())->toBe(1)
        ->and(Report::whereDate('period_start', $jakarta->startOfMonth())->count())->toBe(1)
        ->and(DB::table('reports')->whereBetween('period_end', [$jakarta->startOfMonth(), $jakarta->endOfMonth()])->count())->toBe(1);
})->with('jakarta moments on 10 March');

it('writes timestamptz columns from Jakarta values without shifting the instant', function () {
    $jakarta = CarbonImmutable::parse('2026-03-10 12:00:00', 'Asia/Jakarta');

    DB::table('tasks')->where('id', $this->task->id)->update(['last_activity_at' => $jakarta]);

    expect(CarbonImmutable::parse(DB::table('tasks')->where('id', $this->task->id)->value('last_activity_at'))->utc()->toDateTimeString())
        ->toBe('2026-03-10 05:00:00');
});

/*
| Iteration helpers page through results with timestamps in where clauses and cursors.
*/
function tasksWithActivityTimes(Project $project): array
{
    $base = CarbonImmutable::parse('2026-03-10 05:00:00', 'UTC');
    $offsets = [0, 60, 60, 120, 7200]; // two rows share a timestamp: the cursor must tie-break on id

    return collect($offsets)->map(fn (int $seconds) => Task::factory()->for($project)->create([
        'last_activity_at' => $base->addSeconds($seconds),
    ]))->pluck('id')->all();
}

it('iterates with chunkById, lazyById and cursor() over a Jakarta-bounded timestamp window', function () {
    $ids = tasksWithActivityTimes($this->project);
    $from = CarbonImmutable::parse('2026-03-10 12:00:00', 'Asia/Jakarta'); // = 05:00 UTC, inclusive of all five rows
    $window = fn () => Task::where('last_activity_at', '>=', $from)->where('last_activity_at', '<', $from->addHours(3));

    $seen = [];
    $window()->chunkById(2, function ($chunk) use (&$seen) {
        array_push($seen, ...$chunk->pluck('id')->all());
    });

    expect($seen)->toBe($ids)
        ->and($window()->lazyById(2)->pluck('id')->all())->toBe($ids)
        ->and($window()->orderBy('id')->cursor()->pluck('id')->all())->toBe($ids)
        ->and(DB::table('tasks')->where('last_activity_at', '>=', $from)->where('last_activity_at', '<', $from->addHours(3))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe($ids)
        // A window that starts 7 hours later (what a shifted binding would query) is empty.
        ->and(Task::where('last_activity_at', '>=', $from->addHours(7))->count())->toBe(0);
});

it('pages with cursorPaginate ordered by a timestamp without skipping or repeating rows', function () {
    $ids = tasksWithActivityTimes($this->project);

    $seen = [];
    $cursor = null;
    do {
        $page = Task::orderBy('last_activity_at')->orderBy('id')->cursorPaginate(2, cursor: $cursor);
        array_push($seen, ...$page->getCollection()->pluck('id')->all());
        $cursor = $page->nextCursor();
    } while ($cursor !== null);

    expect($seen)->toBe($ids);

    // The cursor timestamp is compared with `>`: hand-build one from a Jakarta value.
    $jakarta = CarbonImmutable::parse('2026-03-10 12:00:30', 'Asia/Jakarta'); // between row 1 (05:00:00) and rows 2/3 (05:01:00)
    $after = Task::where('last_activity_at', '>', $jakarta)->orderBy('id')->pluck('id')->all();
    expect($after)->toBe(array_slice($ids, 1));
});

/*
| Guard for a Laravel upgrade: Connection::prepareBindings() formats DateTimeInterface values with
| the *query grammar's* getDateFormat(). If that internal changes, this fails loudly.
*/
it('binds datetimes with their UTC offset', function () {
    $connection = DB::connection();
    $jakarta = CarbonImmutable::parse('2026-03-10 12:00:00', 'Asia/Jakarta');

    expect($connection)->toBeInstanceOf(PostgresConnection::class)
        ->and($connection->getQueryGrammar()->getDateFormat())->toBe('Y-m-d H:i:sP')
        ->and($connection->prepareBindings([$jakarta, $jakarta->utc()]))->toBe(['2026-03-10 12:00:00+07:00', '2026-03-10 05:00:00+00:00']);

    // What the server actually receives for a bound DateTimeInterface (not just the query result).
    expect(DB::selectOne('select ?::text as sent', [$jakarta])->sent)->toBe('2026-03-10 12:00:00+07:00')
        ->and(DB::selectOne('select ?::text as sent', [$jakarta->utc()])->sent)->toBe('2026-03-10 05:00:00+00:00');
});
