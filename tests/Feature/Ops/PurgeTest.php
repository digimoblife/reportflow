<?php

use App\Models\Activity;
use App\Models\AiInteraction;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\ReportVersion;
use App\Models\SystemEvent;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\Ops\OpsEvents;
use App\Support\UserContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// PRD §57: hard deletion on request. Dry run first, one transaction, files leave the disk, counts only in the trace.

beforeEach(function () {
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta']);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal']);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Secret client migration']);
    $this->other = Task::factory()->for($this->project)->create(['title' => 'Other task', 'last_activity_at' => '2026-09-25 00:00:00+00']);
    $this->message = InboundMessage::factory()->create(['text' => 'Harbor Portal: secret client migration done']);
    $this->a1 = Activity::factory()->for($this->task)->create(['inbound_message_id' => $this->message->id, 'activity_date' => '2026-09-10', 'summary' => 'Migrated data']);
    $this->a2 = Activity::factory()->for($this->other)->create(['inbound_message_id' => $this->message->id, 'activity_date' => '2026-09-20']);
    $this->a3 = Activity::factory()->for($this->other)->create(['activity_date' => '2026-09-12']);
    TaskEvent::factory()->create(['task_id' => $this->task->id, 'inbound_message_id' => $this->message->id]);
    $this->ai = AiInteraction::factory()->create(['inbound_message_id' => $this->message->id, 'project_id' => $this->project->id]);
    Correction::factory()->create(['inbound_message_id' => $this->message->id]);

    $this->report = Report::factory()->create(['project_id' => $this->project->id]);
    $this->version = ReportVersion::factory()->create(['report_id' => $this->report->id, 'version_no' => 1, 'source_activity_ids' => [$this->a1->id]]);
    $this->report->update(['current_version_id' => $this->version->id]);
    Storage::disk('reports')->put('r/v1.md', 'content');
    Storage::disk('reports')->put('r/v1.pdf', 'content');
    ReportFile::factory()->create(['report_version_id' => $this->version->id, 'file_path' => 'r/v1.md', 'format' => 'md']);
    ReportFile::factory()->create(['report_version_id' => $this->version->id, 'file_path' => 'r/v1.pdf', 'format' => 'pdf']);

    $this->stranger = User::factory()->create(['telegram_user_id' => 888001]);
    asUser($this->stranger->id, function () {
        $p = Project::factory()->create();
        $t = Task::factory()->for($p)->create();
        $this->strangerActivity = Activity::factory()->for($t)->create();
        $this->strangerMessage = InboundMessage::factory()->create();
    });
});

function purge(array $args): int
{
    $code = Artisan::call('reportflow:purge', $args + ['--user' => test()->user->id]);
    app(UserContext::class)->set(test()->user->id);

    return $code;
}

function rows(string $table): int
{
    return DB::table($table)->count();
}

it('changes nothing in a dry run, and says what it would delete', function () {
    $before = collect(['activities', 'tasks', 'inbound_messages', 'ai_interactions', 'report_files'])->mapWithKeys(fn ($t) => [$t => rows($t)]);

    expect(purge(['target' => 'task', 'id' => $this->task->id]))->toBe(0);
    $out = Artisan::output();

    expect($out)->toContain('Dry run')->and($out)->not->toContain('Secret client')
        ->and(collect(['activities', 'tasks', 'inbound_messages', 'ai_interactions', 'report_files'])->mapWithKeys(fn ($t) => [$t => rows($t)])->all())->toBe($before->all());
});

it('deletes nothing without confirmation even with --force', function () {
    $this->artisan('reportflow:purge', ['target' => 'task', 'id' => $this->task->id, '--user' => $this->user->id, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'no')->assertFailed();

    expect(Task::query()->withTrashed()->whereKey($this->task->id)->exists())->toBeTrue();
});

describe('targets', function () {
    it('purges one activity for good and recalculates the task\'s last activity', function () {
        $this->artisan('reportflow:purge', ['target' => 'activity', 'id' => $this->a2->id, '--user' => $this->user->id, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'yes')->assertSuccessful();
        app(UserContext::class)->set($this->user->id);

        expect(Activity::query()->withTrashed()->whereKey($this->a2->id)->exists())->toBeFalse()
            ->and(Activity::query()->whereKey($this->a3->id)->exists())->toBeTrue()
            ->and($this->other->fresh()->last_activity_at->setTimezone('Asia/Jakarta')->toDateString())->toBe('2026-09-12')
            ->and($this->message->fresh())->not->toBeNull();
    });

    it('purges a task with its activities, events and people, and soft-deleted rows too', function () {
        $this->a1->delete();   // soft-deleted rows must go as well

        $this->artisan('reportflow:purge', ['target' => 'task', 'id' => $this->task->id, '--user' => $this->user->id, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'yes')->assertSuccessful();
        app(UserContext::class)->set($this->user->id);

        expect(Task::query()->withTrashed()->whereKey($this->task->id)->exists())->toBeFalse()
            ->and(Activity::query()->withTrashed()->whereKey($this->a1->id)->exists())->toBeFalse()
            ->and(DB::table('task_events')->where('task_id', $this->task->id)->exists())->toBeFalse()
            ->and(Task::query()->whereKey($this->other->id)->exists())->toBeTrue();
    });

    it('warns that a report built from a purged activity still holds its substance', function () {
        purge(['target' => 'activity', 'id' => $this->a1->id]);

        expect(Artisan::output())->toContain('report_versions_built_from_these_activities: 1');
    });

    it('purges a project with tasks, reports, report files on disk and AI logs', function () {
        $this->artisan('reportflow:purge', ['target' => 'project', 'id' => $this->project->id, '--user' => $this->user->id, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'yes')->assertSuccessful();
        app(UserContext::class)->set($this->user->id);

        expect(Project::query()->whereKey($this->project->id)->exists())->toBeFalse()
            ->and(rows('tasks'))->toBe(1)   // the stranger's
            ->and(rows('reports'))->toBe(0)->and(rows('report_versions'))->toBe(0)->and(rows('report_files'))->toBe(0)
            ->and(rows('ai_interactions'))->toBe(0)
            ->and(Storage::disk('reports')->exists('r/v1.md'))->toBeFalse()->and(Storage::disk('reports')->exists('r/v1.pdf'))->toBeFalse()
            ->and(InboundMessage::query()->whereKey($this->message->id)->exists())->toBeTrue();   // messages need --with-messages
    });

    it('purges a report with its versions and files, even an approved one', function () {
        $this->version->forceFill(['approved_at' => now()])->saveQuietly();

        $this->artisan('reportflow:purge', ['target' => 'report', 'id' => $this->report->id, '--user' => $this->user->id, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'yes')->assertSuccessful();
        app(UserContext::class)->set($this->user->id);

        expect(rows('reports'))->toBe(0)->and(rows('report_versions'))->toBe(0)->and(rows('report_files'))->toBe(0)
            ->and(Storage::disk('reports')->exists('r/v1.md'))->toBeFalse()
            ->and(Project::query()->whereKey($this->project->id)->exists())->toBeTrue();
    });

    it('purges a message with its AI logs and corrections, and keeps its activities unless asked', function () {
        $this->artisan('reportflow:purge', ['target' => 'message', 'id' => $this->message->id, '--user' => $this->user->id, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'yes')->assertSuccessful();
        app(UserContext::class)->set($this->user->id);

        expect(InboundMessage::query()->whereKey($this->message->id)->exists())->toBeFalse()
            ->and(rows('ai_interactions'))->toBe(0)->and(rows('corrections'))->toBe(0)
            ->and(Activity::query()->whereKey($this->a1->id)->value('inbound_message_id'))->toBeNull();
    });

    it('purges the activities of a message too with --with-activities', function () {
        $this->artisan('reportflow:purge', ['target' => 'message', 'id' => $this->message->id, '--user' => $this->user->id, '--with-activities' => true, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'yes')->assertSuccessful();
        app(UserContext::class)->set($this->user->id);

        expect(Activity::query()->withTrashed()->whereIn('id', [$this->a1->id, $this->a2->id])->count())->toBe(0)->and(Activity::query()->whereKey($this->a3->id)->exists())->toBeTrue();
    });

    it('purges the source messages of a task with --with-messages', function () {
        $this->artisan('reportflow:purge', ['target' => 'task', 'id' => $this->task->id, '--user' => $this->user->id, '--with-messages' => true, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'yes')->assertSuccessful();
        app(UserContext::class)->set($this->user->id);

        expect(InboundMessage::query()->whereKey($this->message->id)->exists())->toBeFalse()->and(rows('ai_interactions'))->toBe(0);
    });

    it('purges everything of one user and nothing of another', function () {
        $this->artisan('reportflow:purge', ['target' => 'user', '--user' => $this->user->id, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'yes')->assertSuccessful();

        foreach (['activities', 'tasks', 'projects', 'inbound_messages', 'reports', 'report_files', 'ai_interactions', 'corrections', 'task_events'] as $table) {
            expect(rows($table))->toBe(in_array($table, ['activities', 'tasks', 'projects', 'inbound_messages'], true) ? 1 : 0, $table);
        }
        expect(User::query()->count())->toBe(2)->and(Storage::disk('reports')->exists('r/v1.pdf'))->toBeFalse();
    });
});

describe('safety', function () {
    it('cannot reach another user\'s records', function () {
        expect(purge(['target' => 'message', 'id' => $this->strangerMessage->id, '--force' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('not found')
            ->and(DB::table('inbound_messages')->where('id', $this->strangerMessage->id)->exists())->toBeTrue();
    });

    it('rejects an unknown target, a missing id and an ambiguous user', function () {
        expect(purge(['target' => 'nonsense', 'id' => 1]))->toBe(1)
            ->and(purge(['target' => 'task']))->toBe(1);

        Artisan::call('reportflow:purge', ['target' => 'task', 'id' => $this->task->id]);   // two users, no --user
        expect(Artisan::output())->toContain('--user');
    });

    it('is one transaction: a failure leaves everything in place', function () {
        DB::statement('create or replace function purge_boom() returns trigger as $$ begin raise exception \'boom\'; end $$ language plpgsql');
        DB::statement('create trigger purge_boom before delete on tasks for each row execute function purge_boom()');

        try {
            $this->artisan('reportflow:purge', ['target' => 'project', 'id' => $this->project->id, '--user' => $this->user->id, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'yes')->run();
        } catch (Throwable) {
        } finally {
            DB::statement('drop trigger purge_boom on tasks');
        }
        app(UserContext::class)->set($this->user->id);

        expect(Project::query()->whereKey($this->project->id)->exists())->toBeTrue()->and(rows('activities'))->toBe(4)->and(Storage::disk('reports')->exists('r/v1.md'))->toBeTrue();
    });

    it('records counts only: no titles, text or ids of content', function () {
        $this->artisan('reportflow:purge', ['target' => 'task', 'id' => $this->task->id, '--user' => $this->user->id, '--force' => true])->expectsConfirmation('This permanently deletes the rows above and cannot be undone. Continue?', 'yes')->assertSuccessful();

        $event = app(UserContext::class)->runAsSystem(fn () => SystemEvent::query()->where('type', OpsEvents::PURGE)->sole());
        expect($event->context)->toMatchArray(['target' => 'task', 'tasks' => 1])->and(json_encode($event->context))->not->toContain('Secret')->not->toContain('Migrated');
    });
});
