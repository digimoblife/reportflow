<?php

use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Guards against a 7 hour shift when a Carbon in Asia/Jakarta is used as a query value on a
// timestamptz column. The instant 2026-03-10 05:00 UTC is 2026-03-10 12:00 in Jakarta.

beforeEach(function () {
    $this->user = actingAsUser();
    $this->project = Project::factory()->create();

    $this->instantUtc = CarbonImmutable::parse('2026-03-10 05:00:00', 'UTC');
    $this->task = Task::factory()->for($this->project)->create(['last_activity_at' => $this->instantUtc]);
    $this->message = InboundMessage::factory()->create(['received_at' => $this->instantUtc]);

    $this->sameInstantJakarta = $this->instantUtc->setTimezone('Asia/Jakarta');
});

it('matches an exact instant given in Asia/Jakarta (Eloquent)', function () {
    expect($this->sameInstantJakarta->format('H:i'))->toBe('12:00');

    expect(Task::where('last_activity_at', $this->sameInstantJakarta)->count())->toBe(1)
        ->and(InboundMessage::where('received_at', $this->sameInstantJakarta)->count())->toBe(1);
});

it('keeps the boundaries of whereBetween with Jakarta values (Eloquent)', function () {
    // Window [11:59, 12:01] Jakarta contains the instant; a 7 hour shift would move it out.
    $from = $this->sameInstantJakarta->subMinute();
    $to = $this->sameInstantJakarta->addMinute();

    expect(Task::whereBetween('last_activity_at', [$from, $to])->count())->toBe(1)
        ->and(InboundMessage::whereBetween('received_at', [$from, $to])->count())->toBe(1);

    // Window entirely before the instant (11:00–11:59 Jakarta) must not match.
    expect(Task::whereBetween('last_activity_at', [$from->subHour(), $from])->count())->toBe(0);
});

it('matches Jakarta values through the query builder', function () {
    expect(DB::table('tasks')->where('last_activity_at', $this->sameInstantJakarta)->count())->toBe(1)
        ->and(DB::table('inbound_messages')->where('received_at', $this->sameInstantJakarta)->count())->toBe(1)
        ->and(DB::table('tasks')->whereBetween('last_activity_at', [
            $this->sameInstantJakarta->subMinute(),
            $this->sameInstantJakarta->addMinute(),
        ])->count())->toBe(1)
        ->and(DB::table('inbound_messages')->where('received_at', '>', $this->sameInstantJakarta->subMinute())->count())->toBe(1)
        ->and(DB::table('inbound_messages')->where('received_at', '>', $this->sameInstantJakarta->addMinute())->count())->toBe(0);
});

it('also handles mutable Carbon and DateTime values', function () {
    $mutable = $this->instantUtc->toMutable()->setTimezone('Asia/Jakarta');
    $native = new DateTimeImmutable('2026-03-10 12:00:00', new DateTimeZone('Asia/Jakarta'));

    expect(Task::where('last_activity_at', $mutable)->count())->toBe(1)
        ->and(DB::table('tasks')->where('last_activity_at', $native)->count())->toBe(1);
});
