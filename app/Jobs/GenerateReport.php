<?php

namespace App\Jobs;

use App\Jobs\Middleware\WithUserContext;
use App\Models\Report;
use App\Services\Report\ReportGenerator;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Generates the next version of a report on the `reports` queue (PRD §23, §44). Safe to retry: the generation lock
 * makes a duplicate job do nothing. With `waitForEntries` it holds back (releasing itself) until no entry is still
 * being processed or `waitUntil` passes, then generates with whatever is saved ("Tunggu Selesai"). Ids only.
 */
class GenerateReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 2;

    public int $timeout = 300;

    public function __construct(
        public readonly int $reportId,
        public readonly int $userId,
        public readonly string $channel = 'dashboard',
        public readonly bool $waitForEntries = false,
        public readonly ?int $waitUntil = null,
    ) {
        $this->onQueue('reports');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new WithUserContext($this->userId)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestampUTC(($this->waitUntil ?? Carbon::now()->getTimestamp()) + 900);
    }

    public function handle(ReportGenerator $generator): void
    {
        $report = Report::query()->find($this->reportId);

        if ($report === null) {
            return;
        }

        if ($this->waitForEntries && $generator->pendingEntries() > 0 && Carbon::now()->getTimestamp() < ($this->waitUntil ?? 0)) {
            $this->release(15);

            return;
        }

        $generator->generate($report, $this->channel);
    }
}
