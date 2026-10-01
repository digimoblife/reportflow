<?php

namespace App\Jobs;

use App\Jobs\Middleware\WithUserContext;
use App\Models\ReportVersion;
use App\Services\Report\ReportFiles;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renders the PDF and Markdown of one report version on the `reports` queue (one at a time: Gotenberg runs a single
 * Chromium). Idempotent and safe to retry; carries ids only; a failure is logged as a code, never with the document.
 */
class RenderReportFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $reportVersionId,
        public readonly int $userId,
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

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ReportFiles $files): void
    {
        $version = ReportVersion::query()->find($this->reportVersionId);

        if ($version !== null) {
            $files->ensure($version);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('report.files_failed', ['report_version_id' => $this->reportVersionId, 'exception' => $e::class, 'code' => $e->getMessage() === '' ? null : mb_substr($e->getMessage(), 0, 60)]);
    }
}
