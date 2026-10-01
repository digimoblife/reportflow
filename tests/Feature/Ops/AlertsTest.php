<?php

use App\Jobs\SendReminder;
use App\Models\SystemEvent;
use App\Models\User;
use App\Services\Ops\FakeServiceProbe;
use App\Services\Ops\HealthChecks;
use App\Services\Ops\OpsEvents;
use App\Services\Ops\ServiceProbe;
use App\Support\UserContext;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 10, 12, 0, 0, 'UTC'));
    Cache::flush();
    $this->admin = User::factory()->create(['telegram_user_id' => 555001, 'default_language' => 'id']);
    $this->probe = app(ServiceProbe::class);
    expect($this->probe)->toBeInstanceOf(FakeServiceProbe::class);
    $this->statusFile = sys_get_temp_dir().'/reportflow-backup-status-'.uniqid().'.json';
    config(['ops.backup_status_file' => $this->statusFile]);
    allHealthy();
});

afterEach(function () {
    Carbon::setTestNow();
    @unlink($this->statusFile);
});

/** Every watched thing is fine: workers beat, the scheduler beats, a fresh backup, a recent restore test. */
function allHealthy(): void
{
    foreach (HealthChecks::WORKER_QUEUES as $queue) {
        Cache::put(HealthChecks::workerKey($queue), now('UTC')->getTimestamp(), 3600);
    }
    Cache::put(HealthChecks::HEARTBEAT_SCHEDULER, now('UTC')->getTimestamp(), 3600);
    writeBackupStatus(['last_status' => 'ok', 'last_success_at' => now('UTC')->subHours(3)->toIso8601String(), 'first_success_at' => now('UTC')->subDays(10)->toIso8601String()]);
    app(UserContext::class)->runAsSystem(fn () => SystemEvent::query()->create(['type' => OpsEvents::RESTORE_VERIFIED, 'context' => [], 'user_id' => null, 'created_at' => now('UTC')->subDays(3)]));
}

function writeBackupStatus(array $status): void
{
    file_put_contents(config('ops.backup_status_file'), json_encode($status));
}

function check(): void
{
    Artisan::call('ops:check');
    app(UserContext::class)->clear();
}

function alerts(): array
{
    return array_column(fakeTelegram()->sent, 'text');
}

it('is quiet when everything is fine', function () {
    check();

    expect(alerts())->toBe([])->and(collect(app(HealthChecks::class)->conditions())->where('active', true)->all())->toBe([]);
});

describe('services and disk', function () {
    it('raises one alert per outage, says when it is over, and tells the operator in their language', function (string $service, string $text) {
        $this->probe->{$service} = false;

        check();
        check();   // still down: no second message
        expect(alerts())->toHaveCount(1)->and(alerts()[0])->toContain($text)->and(fakeTelegram()->sent[0]['chat_id'])->toBe(555001);

        $this->probe->{$service} = true;
        check();
        check();

        expect(alerts())->toHaveCount(2)->and(fakeTelegram()->sent[1]['text'])->not->toBe(alerts()[0]);
    })->with([
        'database' => ['database', 'database tidak bisa dihubungi'],
        'redis' => ['redis', 'Redis tidak bisa dihubungi'],
        'gotenberg' => ['gotenberg', 'Gotenberg (pembuat PDF) tidak terjangkau'],
    ]);

    it('watches the fullest disk and quotes the numbers', function () {
        $this->probe->disk = [storage_path('app') => 41.0, base_path() => 86.5];

        check();

        expect(alerts())->toHaveCount(1)->and(alerts()[0])->toContain('86.5%')->toContain('80%');

        $this->probe->disk = [];
        check();
        expect(alerts())->toHaveCount(2)->and(alerts()[1])->toContain('40%');
    });

    it('does not cry wolf when the disk cannot be read', function () {
        $this->probe->defaultDisk = null;

        check();

        expect(alerts())->toBe([]);
    });

    it('repeats a lasting problem once a day, not more often', function () {
        $this->probe->redis = false;
        check();
        Carbon::setTestNow(now()->addHours(23));
        allHealthy();
        check();
        expect(alerts())->toHaveCount(1);

        Carbon::setTestNow(now()->addHours(2));
        allHealthy();
        check();
        expect(alerts())->toHaveCount(2);
    });

    it('sends in English to an English-speaking operator', function () {
        $this->admin->update(['default_language' => 'en']);
        $this->probe->database = false;

        check();

        expect(alerts()[0])->toContain('The database cannot be reached');
    });
});

describe('queues and workers', function () {
    it('alerts when jobs pile up', function () {
        Queue::fake();
        foreach (range(1, 60) as $_) {
            Queue::push(new SendReminder(1, 1), '', 'default');
        }

        check();

        expect(alerts())->toHaveCount(1)->and(alerts()[0])->toContain('60')->toContain('50');
    });

    it('alerts on jobs that failed in the last 24 hours, not on old ones', function () {
        DB::table('failed_jobs')->insert(['uuid' => 'old', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(2)]);
        check();
        expect(alerts())->toBe([]);

        DB::table('failed_jobs')->insert(['uuid' => 'new', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subHour()]);
        check();
        expect(alerts())->toHaveCount(1)->and(alerts()[0])->toContain('1 pekerjaan antrean gagal');
    });

    it('notices a worker that stopped beating, and one that was never seen', function () {
        Cache::forget(HealthChecks::workerKey('reports'));
        check();
        expect(alerts())->toHaveCount(1)->and(alerts()[0])->toContain('Worker laporan');

        Carbon::setTestNow(now()->addMinutes(11));
        Cache::put(HealthChecks::HEARTBEAT_SCHEDULER, now('UTC')->getTimestamp(), 3600);
        check();
        expect(collect(alerts())->contains(fn ($t) => str_contains($t, 'Worker antrean utama')))->toBeTrue();
    });

    it('records a heartbeat for every queue a worker listens to', function () {
        Cache::flush();

        Event::dispatch(new Looping('redis', 'default,ai', []));

        expect(Cache::get(HealthChecks::workerKey('default')))->toBe(now('UTC')->getTimestamp())->and(Cache::get(HealthChecks::workerKey('ai')))->toBe(now('UTC')->getTimestamp())
            ->and(Cache::get(HealthChecks::workerKey('reports')))->toBeNull();
    });
});

describe('backups', function () {
    it('alerts when there is no backup status at all', function () {
        unlink($this->statusFile);

        check();

        expect(alerts())->toHaveCount(1)->and(alerts()[0])->toContain('Backup terakhir');
    });

    it('alerts when the last backup failed, or is older than 26 hours', function () {
        writeBackupStatus(['last_status' => 'failed', 'last_success_at' => now('UTC')->subHours(3)->toIso8601String()]);
        check();
        expect(alerts())->toHaveCount(1)->and(alerts()[0])->toContain('GAGAL');

        writeBackupStatus(['last_status' => 'ok', 'last_success_at' => now('UTC')->subHours(30)->toIso8601String()]);
        check();
        expect(collect(alerts())->contains(fn ($t) => str_contains($t, '30 jam lalu')))->toBeTrue();
    });

    it('asks for a restore test every 35 days', function () {
        app(UserContext::class)->runAsSystem(fn () => SystemEvent::query()->where('type', OpsEvents::RESTORE_VERIFIED)->delete());
        writeBackupStatus(['last_status' => 'ok', 'last_success_at' => now('UTC')->subHours(1)->toIso8601String(), 'first_success_at' => now('UTC')->subDays(40)->toIso8601String()]);

        check();
        expect(alerts())->toHaveCount(1)->and(alerts()[0])->toContain('Uji restore')->toContain('40');

        app(UserContext::class)->runAsSystem(fn () => OpsEvents::record(OpsEvents::RESTORE_VERIFIED, ['archive' => 'x']));
        check();
        expect(alerts())->toHaveCount(2);   // the "recovered" message
    });
});

describe('who is told', function () {
    it('sends to the configured admin, else to the first registered user, and to nobody when there is none', function () {
        $second = User::factory()->create(['telegram_user_id' => 777002]);
        config(['ops.admin_telegram_user_id' => 777002]);
        $this->probe->redis = false;
        check();
        expect(fakeTelegram()->sent[0]['chat_id'])->toBe($second->telegram_user_id);

        Cache::flush();
        allHealthy();
        fakeTelegram()->sent = [];
        config(['ops.admin_telegram_user_id' => null]);
        check();
        expect(fakeTelegram()->sent[0]['chat_id'])->toBe(555001);

        User::query()->update(['telegram_user_id' => null]);
        Cache::flush();
        allHealthy();
        fakeTelegram()->sent = [];
        check();   // must not crash
        expect(fakeTelegram()->sent)->toBe([]);
    });

    it('keeps a trace of each alert, with the key and nothing else', function () {
        $this->probe->database = false;
        check();
        $this->probe->database = true;
        check();

        $events = app(UserContext::class)->runAsSystem(fn () => SystemEvent::query()->where('type', OpsEvents::ALERT)->orderBy('id')->get());
        expect($events->pluck('context')->all())->toBe([['key' => 'database', 'state' => 'raised'], ['key' => 'database', 'state' => 'recovered']])->and($events->pluck('user_id')->unique()->all())->toBe([null]);
    });
});

describe('the deep health endpoint', function () {
    it('answers 200 when the system works and 503 when something essential is down, with booleans only', function () {
        $this->get('/health/deep')->assertOk()->assertExactJson(['status' => 'ok']);

        $this->probe->redis = false;
        $this->get('/health/deep')->assertStatus(503)->assertExactJson(['status' => 'degraded']);
    });

    it('is degraded when the scheduler stopped or a worker is gone', function () {
        Carbon::setTestNow(now()->addMinutes(4));
        $this->get('/health/deep')->assertStatus(503);

        allHealthy();
        Cache::forget(HealthChecks::workerKey('default'));
        $this->get('/health/deep')->assertStatus(503);
    });

    it('keeps the plain liveness check untouched', function () {
        $this->probe->database = false;

        $this->get('/health')->assertOk()->assertExactJson(['status' => 'ok']);
    });
});

it('refuses to boot in production with the fake probe', function () {
    expect(fn () => withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'http', 'AI_PROVIDER' => 'deepseek', 'PDF_RENDERER' => 'gotenberg', 'OPS_PROBE' => 'fake'], fn () => null))
        ->toThrow(RuntimeException::class, 'OPS_PROBE must be "real" in production');
});
