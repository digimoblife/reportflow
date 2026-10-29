<?php

namespace App\Services\Report;

use App\Enums\Language;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Jobs\GenerateReport;
use App\Models\Project;
use App\Models\Report;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * "Generate" from the dashboard (PRD §23, §37): picks or creates the report of a period and queues the generation, honouring
 * one generation per report at a time and the pre-generate choice about entries still being processed. Needs a UserContext.
 */
class ReportRequests
{
    public const QUEUED = 'queued';

    public const BUSY = 'busy';

    public const INVALID = 'invalid';

    public function __construct(
        private readonly UserContext $context,
        private readonly ReportGenerator $generator,
    ) {}

    /**
     * @param  bool  $waitForEntries  "Tunggu Selesai": hold back until nothing is being processed (or the wait limit)
     * @return array{status: string, report: Report|null}
     */
    public function request(Project $project, string $start, string $end, Language $language, bool $waitForEntries = false, string $channel = 'dashboard', ?ReportType $type = null): array
    {
        try {
            $report = $this->generator->findOrCreate($project, $type ?? $this->typeOf($start, $end), $start, $end, $language);
        } catch (InvalidArgumentException|InvalidFormatException) {
            return ['status' => self::INVALID, 'report' => null];
        }

        $now = Carbon::now('UTC');

        if ($report->generation_lock_until !== null && $report->generation_lock_until->greaterThan($now)) {
            return ['status' => self::BUSY, 'report' => $report];
        }

        Report::query()->whereKey($report->id)->update(['status' => ReportStatus::Generating]);

        GenerateReport::dispatch(
            $report->id,
            $this->context->requireUserId(),
            $channel,
            $waitForEntries,
            $now->copy()->addMinutes((int) config('reports.wait_minutes'))->getTimestamp(),
        );

        return ['status' => self::QUEUED, 'report' => $report];
    }

    /** A whole calendar month is a monthly report; Monday-Sunday week is weekly; any other range is custom (PRD §36, PRD Scrum §6). */
    public function typeOf(string $start, string $end): ReportType
    {
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);

        if ($from->day === 1 && $from->endOfMonth()->format('Y-m-d') === $to->format('Y-m-d')) {
            return ReportType::Monthly;
        }

        if ($from->isMonday() && $to->isSunday() && (int) $from->diffInDays($to) === 6) {
            return ReportType::Weekly;
        }

        return ReportType::Custom;
    }
}
