<?php

namespace App\Services\Report;

use App\Enums\ReportFileFormat;
use App\Models\ReportFile;
use App\Models\ReportVersion;
use App\Services\Report\Pdf\PdfRenderer;
use App\Support\UserContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Makes and stores the files of a report version (PRD §64, CLAUDE.md rule 10): the Markdown and the PDF are always made
 * together. The PDF is rendered first; only when it succeeds are both written (private disk) and both rows recorded, and
 * a failure at any step removes what was written, so a PDF can never exist without its .md (or the other way round).
 * Idempotent: a version that already has both files is left alone. Needs a UserContext.
 */
class ReportFiles
{
    public function __construct(
        private readonly UserContext $context,
        private readonly ReportMarkdown $markdown,
        private readonly ReportHtml $html,
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * @return bool true when files were created now, false when they already existed
     *
     * @throws Throwable the PDF engine's or the disk's failure; nothing is left behind
     */
    public function ensure(ReportVersion $version): bool
    {
        if (ReportFile::query()->where('report_version_id', $version->id)->count() >= 2) {
            return false;
        }

        $markdown = $this->markdown->build($version);
        $pdf = $this->pdf->render($this->html->render($version), $this->html->footer($version));

        $disk = Storage::disk('reports');
        $base = $this->context->requireUserId().'/'.$version->report_id.'/v'.$version->version_no;
        $written = [];

        try {
            foreach ([ReportFileFormat::Md->value => $markdown, ReportFileFormat::Pdf->value => $pdf] as $format => $bytes) {
                $disk->put("{$base}.{$format}", $bytes);
                $written[$format] = ["{$base}.{$format}", hash('sha256', $bytes)];
            }

            DB::transaction(function () use ($version, $written): void {
                foreach ($written as $format => [$path, $checksum]) {
                    ReportFile::query()->updateOrCreate(
                        ['report_version_id' => $version->id, 'format' => ReportFileFormat::from($format)],
                        ['file_path' => $path, 'checksum' => $checksum],
                    );
                }
            });
        } catch (Throwable $e) {
            foreach ($written as [$path]) {
                $disk->delete($path);
            }

            throw $e;
        }

        return true;
    }
}
