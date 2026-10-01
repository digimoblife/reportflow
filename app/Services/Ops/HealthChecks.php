<?php

namespace App\Services\Ops;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * The conditions `ops:check` watches and `/health/deep` reports (PRD §78, §84): infrastructure reachable, disk, queues and
 * their workers, failed jobs, backup and restore freshness. Read-only.
 */
class HealthChecks
{
    public const HEARTBEAT_SCHEDULER = 'ops:heartbeat:scheduler';

    public const WORKER_QUEUES = ['default', 'reports'];

    public function __construct(
        private readonly ServiceProbe $probe,
    ) {}

    public static function workerKey(string $queue): string
    {
        return 'ops:heartbeat:worker:'.$queue;
    }

    /**
     * @return list<OpsCondition>
     */
    public function conditions(): array
    {
        $a = (array) config('ops.alerts');
        $disk = $this->disk();
        $backlog = max([0, ...array_values($this->backlog())]);
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', Carbon::now('UTC')->subDay())->count();
        $out = [
            new OpsCondition('database', ! $this->probe->database()),
            new OpsCondition('redis', ! $this->probe->redis()),
            new OpsCondition('gotenberg', ! $this->probe->gotenberg()),
            new OpsCondition('disk', $disk !== null && $disk >= $a['disk_percent'], $disk, $a['disk_percent']),
            new OpsCondition('queue_backlog', $backlog > $a['queue_backlog'], $backlog, $a['queue_backlog']),
            new OpsCondition('failed_jobs', $failed >= $a['failed_jobs_new'], $failed),
        ];

        foreach (self::WORKER_QUEUES as $queue) {
            $age = $this->heartbeatAgeMinutes(self::workerKey($queue));
            $out[] = new OpsCondition('worker_'.$queue, $age === null || $age > $a['heartbeat_max_age_minutes'], $age, $a['heartbeat_max_age_minutes']);
        }

        return [...$out, ...$this->backup($a)];
    }

    /** Liveness for an external uptime monitor: false means "page someone". */
    public function healthy(): bool
    {
        foreach ($this->conditions() as $c) {
            if ($c->active && in_array($c->key, ['database', 'redis', 'gotenberg', 'worker_default', 'worker_reports'], true)) {
                return false;
            }
        }

        $scheduler = $this->heartbeatAgeMinutes(self::HEARTBEAT_SCHEDULER);

        return $scheduler !== null && $scheduler <= 3;
    }

    /**
     * Highest disk use of the volumes that matter (the report storage and the root), in percent.
     */
    private function disk(): ?float
    {
        $values = array_filter([
            $this->probe->diskUsedPercent(storage_path('app')),
            $this->probe->diskUsedPercent(base_path()),
        ], fn ($v): bool => $v !== null);

        return $values === [] ? null : max($values);
    }

    /**
     * @return array<string, int>
     */
    private function backlog(): array
    {
        $sizes = [];

        foreach (['default', 'ai', 'reports'] as $queue) {
            try {
                $sizes[$queue] = (int) Queue::size($queue);
            } catch (Throwable) {
                $sizes[$queue] = 0;
            }
        }

        return $sizes;
    }

    private function heartbeatAgeMinutes(string $key): ?float
    {
        $at = Cache::get($key);

        return is_numeric($at) ? round((Carbon::now('UTC')->getTimestamp() - (int) $at) / 60, 1) : null;
    }

    /**
     * Backup status is written by the backup service (a JSON file); restore verification is a field of the same file
     * (written by restore-test.sh).
     *
     * @param  array<string, mixed>  $a
     * @return list<OpsCondition>
     */
    private function backup(array $a): array
    {
        $status = $this->backupStatus();
        $now = Carbon::now('UTC');
        $last = isset($status['last_success_at']) ? Carbon::parse((string) $status['last_success_at']) : null;
        $first = isset($status['first_success_at']) ? Carbon::parse((string) $status['first_success_at']) : $last;
        $ageHours = $last === null ? null : round($last->diffInMinutes($now) / 60, 1);

        $verifiedAt = isset($status['last_restore_verified_at']) ? Carbon::parse((string) $status['last_restore_verified_at']) : null;
        $restoreAgeDays = $verifiedAt === null ? ($first === null ? null : $first->diffInDays($now)) : $verifiedAt->diffInDays($now);

        return [
            new OpsCondition('backup_failed', ($status['last_status'] ?? null) === 'failed'),
            new OpsCondition('backup_stale', $status === null || $ageHours === null || $ageHours > $a['backup_max_age_hours'], $ageHours, $a['backup_max_age_hours']),
            new OpsCondition('restore_overdue', $restoreAgeDays !== null && $restoreAgeDays > $a['restore_max_age_days'], $restoreAgeDays === null ? null : (int) $restoreAgeDays, $a['restore_max_age_days']),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function backupStatus(): ?array
    {
        $file = (string) config('ops.backup_status_file');

        if (! is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : null;
    }
}
