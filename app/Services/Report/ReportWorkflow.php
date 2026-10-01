<?php

namespace App\Services\Report;

use App\Enums\ReportCreatedBy;
use App\Enums\ReportStatus;
use App\Jobs\RenderReportFiles;
use App\Models\Report;
use App\Models\ReportVersion;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Review steps on a report (PRD §37, §42, §43): edit a section's Markdown, approve, cancel. Every edit is a NEW version
 * (history is never rewritten); an approved version is immutable and stays what it was, later edits start a new
 * version and put the report back in review. All steps are guarded by the version the person was looking at.
 * Needs a UserContext.
 */
class ReportWorkflow
{
    public function __construct(private readonly UserContext $context) {}

    /**
     * Saves changed section texts as a new version made by the user. Returns null when nothing changed.
     *
     * @param  array<string, string>  $sections  section key => Markdown (only keys of the current version count)
     *
     * @throws StaleReportException when the current version is not $expectedVersionId any more
     * @throws InvalidArgumentException when the report cannot be edited now
     */
    public function saveEdit(Report $report, int $expectedVersionId, array $sections, string $channel = 'dashboard'): ?ReportVersion
    {
        $version = DB::transaction(function () use ($report, $expectedVersionId, $sections, $channel): ?ReportVersion {
            $locked = $this->lock($report, $expectedVersionId);
            $current = ReportVersion::query()->findOrFail($expectedVersionId);
            $content = $current->content;
            $changed = false;

            foreach ($content['sections'] as $i => $section) {
                $key = (string) $section['key'];

                if (isset($sections[$key]) && trim($sections[$key]) !== trim((string) $section['markdown'])) {
                    $content['sections'][$i]['markdown'] = trim($sections[$key]);
                    $content['sections'][$i]['fallback'] = false;
                    $changed = true;
                }
            }

            if (! $changed) {
                return null;
            }

            return $this->append($locked, $current, $content, ReportCreatedBy::UserEdit, $channel, null);
        });

        if ($version !== null) {
            RenderReportFiles::dispatch($version->id, $this->context->requireUserId());
        }

        return $version;
    }

    /**
     * Approves the current version: it becomes immutable and the report approved.
     *
     * @throws StaleReportException
     * @throws InvalidArgumentException
     */
    public function approve(Report $report, int $expectedVersionId): ReportVersion
    {
        $version = DB::transaction(function () use ($report, $expectedVersionId): ReportVersion {
            $this->lock($report, $expectedVersionId, requireReview: true);
            $version = ReportVersion::query()->findOrFail($expectedVersionId);
            $now = Carbon::now('UTC');

            $version->forceFill(['approved_at' => $now])->save();
            Report::query()->whereKey($report->id)->update(['status' => ReportStatus::Approved, 'approved_at' => $now]);

            return $version;
        });

        RenderReportFiles::dispatch($version->id, $this->context->requireUserId());   // no-op when the files already exist

        return $version;
    }

    /**
     * Cancels a report that was not approved. The row stays (history); its period can be reported again.
     *
     * @throws InvalidArgumentException
     */
    public function cancel(Report $report): void
    {
        DB::transaction(function () use ($report): void {
            $locked = Report::query()->lockForUpdate()->findOrFail($report->id);

            if (in_array($locked->status, [ReportStatus::Approved, ReportStatus::Generating, ReportStatus::Cancelled], true)) {
                throw new InvalidArgumentException('This report cannot be cancelled now.');
            }

            $locked->update(['status' => ReportStatus::Cancelled]);
        });
    }

    /**
     * Adds a version built from $current's content, in a transaction the caller already opened.
     *
     * @param  array<string, mixed>  $content
     * @param  list<int>|null  $sourceActivityIds  the new version's frozen activities (default: the current version's)
     */
    public function append(Report $report, ReportVersion $current, array $content, ReportCreatedBy $by, string $channel, ?string $instruction, ?array $sourceActivityIds = null): ReportVersion
    {
        $next = (int) ReportVersion::query()->where('report_id', $report->id)->max('version_no') + 1;

        $version = ReportVersion::query()->create([
            'report_id' => $report->id,
            'version_no' => $next,
            'content' => $content,
            'data_snapshot_at' => $current->data_snapshot_at,
            'source_activity_ids' => $sourceActivityIds ?? $current->source_activity_ids,
            'created_by' => $by,
            'source_channel' => $channel,
            'instruction' => $instruction,
            'version' => 1,
        ]);

        Report::query()->whereKey($report->id)->update(['current_version_id' => $version->id, 'status' => ReportStatus::InReview, 'approved_at' => null]);

        return $version;
    }

    /**
     * Locks the report row and checks the person is still looking at its current version.
     */
    public function lock(Report $report, int $expectedVersionId, bool $requireReview = false): Report
    {
        $locked = Report::query()->lockForUpdate()->findOrFail($report->id);

        if ($locked->current_version_id !== $expectedVersionId) {
            throw new StaleReportException($locked->id);
        }

        if (in_array($locked->status, [ReportStatus::Generating, ReportStatus::Cancelled], true)) {
            throw new InvalidArgumentException('This report cannot be changed now.');
        }

        if ($requireReview && $locked->status !== ReportStatus::InReview) {
            throw new InvalidArgumentException('Only a report in review can be approved.');
        }

        return $locked;
    }
}
