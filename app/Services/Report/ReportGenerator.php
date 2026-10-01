<?php

namespace App\Services\Report;

use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\ReportCreatedBy;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Jobs\RenderReportFiles;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportVersion;
use App\Models\User;
use App\Services\Ai\AiExtractionFailed;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AIService;
use App\Services\Ops\OpsEvents;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Builds a report version from a snapshot of the period (PRD §36–§38, §44). Hybrid by decision: the facts of every
 * section are deterministic (ReportFactsBuilder); the AI only writes the short narrative of the sections that ask for
 * one, from that section's data alone, and the backend rejects anything it cannot trace to the data (falling back to a
 * fixed sentence, never to invented text). One generation per report at a time (`generation_lock_until`).
 * Needs a UserContext.
 */
class ReportGenerator
{
    public const MAX_PERIOD_DAYS = 366;

    public function __construct(
        private readonly UserContext $context,
        private readonly ReportDataSelector $selector,
        private readonly ReportFactsBuilder $facts,
        private readonly ReportTemplates $templates,
        private readonly AIService $ai,
        private readonly SectionNarrativeValidator $validator,
    ) {}

    /**
     * Messages that are still being processed: their notes are not in the data yet (PRD §23 pre-generate check).
     */
    public function pendingEntries(): int
    {
        return InboundMessage::query()->whereIn('status', [InboundMessageStatus::Received, InboundMessageStatus::Processing])->count();
    }

    /**
     * The report for a project, type, period and language: the existing one (any status but cancelled) or a new draft.
     *
     * @throws InvalidArgumentException when the period is not usable
     */
    public function findOrCreate(Project $project, ReportType $type, string $start, string $end, Language $language): Report
    {
        $from = CarbonImmutable::parse($start);
        $to = CarbonImmutable::parse($end);

        if ($to->lessThan($from) || $from->diffInDays($to) > self::MAX_PERIOD_DAYS) {
            throw new InvalidArgumentException('Invalid report period.');
        }

        $existing = Report::query()->where('project_id', $project->id)->where('type', $type)
            ->where('period_start', $from->format('Y-m-d'))->where('period_end', $to->format('Y-m-d'))
            ->where('language', $language)->where('status', '!=', ReportStatus::Cancelled)->first();

        return $existing ?? Report::query()->create([
            'project_id' => $project->id,
            'type' => $type,
            'period_start' => $from->format('Y-m-d'),
            'period_end' => $to->format('Y-m-d'),
            'language' => $language,
            'template_id' => $this->templates->forLanguage($language)->id,
            'status' => ReportStatus::Draft,
        ]);
    }

    /**
     * Takes the generation lock (one process per report). False when another generation holds it.
     */
    public function lock(Report $report): bool
    {
        $now = Carbon::now('UTC');

        return Report::query()->whereKey($report->id)
            ->where(fn ($q) => $q->whereNull('generation_lock_until')->orWhere('generation_lock_until', '<', $now))
            ->update(['generation_lock_until' => $now->copy()->addSeconds((int) config('reports.lock_seconds')), 'status' => ReportStatus::Generating]) === 1;
    }

    /**
     * Writes the next version from a fresh snapshot. Null when the lock could not be taken (someone else is generating).
     */
    public function generate(Report $report, string $channel = 'dashboard'): ?ReportVersion
    {
        $previous = $report->status === ReportStatus::Generating ? $this->settledStatus($report) : $report->status;

        if (! $this->lock($report)) {
            return null;
        }

        $startedAt = hrtime(true);

        try {
            $user = User::query()->findOrFail($this->context->requireUserId());
            $report = Report::query()->with('project')->findOrFail($report->id);
            $data = $this->selector->select($report->project, $report->period_start->format('Y-m-d'), $report->period_end->format('Y-m-d'), $user->timezone);

            $version = $this->writeVersion($report, $data, $this->composeSections($report, $data), ReportCreatedBy::AiGenerate, $channel, null, (int) round((hrtime(true) - $startedAt) / 1_000_000));
        } catch (Throwable $e) {
            Report::query()->whereKey($report->id)->update(['generation_lock_until' => null, 'status' => $previous]);
            OpsEvents::record(OpsEvents::REPORT_FAILED, ['report_id' => $report->id, 'exception' => $e::class]);

            throw $e;
        }

        return $version;
    }

    /**
     * The status a report has when nothing is running: draft without a version, approved while its current version is an
     * approved one, otherwise in review.
     */
    public function settledStatus(Report $report): ReportStatus
    {
        $current = $report->current_version_id === null ? null : ReportVersion::query()->find($report->current_version_id);

        return match (true) {
            $current === null => ReportStatus::Draft,
            $current->approved_at !== null => ReportStatus::Approved,
            default => ReportStatus::InReview,
        };
    }

    /**
     * Content of every section for a data set: facts, plus the narrative where the template wants one.
     *
     * @return list<array{key: string, title: string, markdown: string, fallback: bool}>
     */
    public function composeSections(Report $report, ReportDataSet $data, ?string $onlySection = null, ?string $instruction = null): array
    {
        $language = $report->language;
        $sections = [];

        foreach ($this->templates->sections($language) as $section) {
            if ($onlySection !== null && $section['key'] !== $onlySection) {
                continue;
            }

            $facts = $this->facts->facts($section['key'], $data, $report->type, $language);
            $narrative = '';
            $fallback = false;

            if ($section['narrative'] && ! (in_array($section['key'], ['detailed', 'ongoing'], true) && $this->facts->isEmpty($facts, $language))) {
                [$narrative, $fallback] = $this->narrative($report, $section['key'], $data, $instruction);
            }

            $sections[] = [
                'key' => $section['key'],
                'title' => $section['title'],
                'markdown' => trim(implode("\n\n", array_filter([$narrative, $facts], fn (string $s): bool => $s !== ''))),
                'fallback' => $fallback,
            ];
        }

        return $sections;
    }

    /**
     * @param  list<array{key: string, title: string, markdown: string, fallback: bool}>  $sections
     */
    public function writeVersion(Report $report, ReportDataSet $data, array $sections, ReportCreatedBy $by, string $channel, ?string $instruction, ?int $generationMs = null): ReportVersion
    {
        $version = DB::transaction(function () use ($report, $data, $sections, $by, $channel, $instruction, $generationMs): ReportVersion {
            $next = (int) ReportVersion::query()->where('report_id', $report->id)->max('version_no') + 1;

            $version = ReportVersion::query()->create([
                'report_id' => $report->id,
                'version_no' => $next,
                'content' => [
                    'title' => $this->facts->title($data, $report->type, $report->language),
                    'language' => $report->language->value,
                    'sections' => $sections,
                    'counts' => $data->counts(),
                    'meta' => ['generation_ms' => $generationMs],
                ],
                'data_snapshot_at' => $data->snapshotAt,
                'source_activity_ids' => $data->sourceActivityIds(),
                'created_by' => $by,
                'source_channel' => $channel,
                'instruction' => $instruction,
                'version' => 1,
            ]);

            Report::query()->whereKey($report->id)->update([
                'current_version_id' => $version->id,
                'status' => ReportStatus::InReview,
                'generation_lock_until' => null,
                'approved_at' => null,
            ]);

            return $version;
        });

        // PDF + Markdown are made on the `reports` queue, once the version is committed.
        RenderReportFiles::dispatch($version->id, $this->context->requireUserId());

        return $version;
    }

    /**
     * @return array{0: string, 1: bool} the narrative Markdown and whether the fixed fallback was used
     */
    private function narrative(Report $report, string $section, ReportDataSet $data, ?string $instruction): array
    {
        $fallback = $this->facts->fallbackNarrative($section, $data, $report->type, $report->language);

        if ($data->counts()['activities'] === 0) {
            return [$fallback, true];
        }

        $payload = ReportSectionPayload::build($section, $data, $report->type, $report->language, $this->facts, $instruction);

        try {
            $result = $this->ai->writeReportSection(
                $payload->data,
                fn (array $decoded): array => $this->validator->errors($decoded, $payload),
                $report->id,
                $report->project_id,
            );

            return [$this->validator->render($result->data, $payload, $this->facts), false];
        } catch (AiExtractionFailed|AiProviderException|JsonException $e) {
            Log::warning('report.section_fallback', ['report_id' => $report->id, 'section' => $section, 'exception' => $e::class]);

            return [$fallback, true];
        }
    }
}
