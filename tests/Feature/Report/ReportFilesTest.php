<?php

use App\Enums\ReportFileFormat;
use App\Jobs\RenderReportFiles;
use App\Models\Project;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\ReportVersion;
use App\Models\User;
use App\Services\Report\Pdf\FakePdfRenderer;
use App\Services\Report\Pdf\GotenbergPdfRenderer;
use App\Services\Report\Pdf\PdfRenderer;
use App\Services\Report\Pdf\PdfRenderException;
use App\Services\Report\ReportFiles;
use App\Services\Report\ReportHtml;
use App\Services\Report\ReportMarkdown;
use App\Services\Report\SignedDownload;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 1, 9, 0, 0, 'Asia/Jakarta'));
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta']);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal']);
    $this->report = Report::factory()->create(['project_id' => $this->project->id]);
    $this->version = makeVersion($this->report);
    $this->pdf = app(PdfRenderer::class);
});

afterEach(fn () => Carbon::setTestNow());

function makeVersion(Report $report, ?array $sections = null, string $language = 'en'): ReportVersion
{
    return ReportVersion::factory()->create([
        'report_id' => $report->id,
        'version_no' => (int) ReportVersion::query()->where('report_id', $report->id)->max('version_no') + 1,
        'content' => [
            'title' => 'Harbor Portal Monthly Report — September 2026',
            'language' => $language,
            'sections' => $sections ?? [
                ['key' => 'overview', 'title' => 'Monthly Overview', 'markdown' => "During the period, **Shipment Tracking API** progressed.\n\n- **Activities:** 3", 'fallback' => false],
                ['key' => 'completed', 'title' => 'Completed Tasks', 'markdown' => "| Task | Completed on |\n| --- | --- |\n| Invoice Export | 20 Sep 2026 |", 'fallback' => false],
            ],
        ],
        'data_snapshot_at' => '2026-10-01 02:00:00+00',
    ]);
}

describe('markdown and html', function () {
    it('builds the .md with the title and every section in order', function () {
        $md = app(ReportMarkdown::class)->build($this->version);

        expect($md)->toStartWith("# Harbor Portal Monthly Report — September 2026\n\n## Monthly Overview\n\n")
            ->and(strpos($md, '## Monthly Overview'))->toBeLessThan(strpos($md, '## Completed Tasks'))
            ->and($md)->toEndWith("| Invoice Export | 20 Sep 2026 |\n");
    });

    it('renders the template: title, ordered sections, real tables, language, and no fixed persona', function () {
        $html = app(ReportHtml::class)->render($this->version);

        expect($html)->toContain('<html lang="en">')->toContain('<h1>Harbor Portal Monthly Report — September 2026</h1>')
            ->and(strpos($html, 'Monthly Overview'))->toBeLessThan(strpos($html, 'Completed Tasks'))
            ->and($html)->toContain('<table>')->toContain('<th>Task</th>')->toContain('<td>Invoice Export</td>')
            ->and($html)->toContain('@page')->toContain('Generated: 2026-10-01 02:00 UTC')
            ->and($html)->not->toContain('Pak Carik');
    });

    it('speaks the report language in the fixed parts', function () {
        $html = app(ReportHtml::class)->render(makeVersion($this->report, language: 'id'));

        expect($html)->toContain('<html lang="id">')->toContain('Dibuat: 2026-10-01 02:00 UTC');
    });

    it('strips raw HTML and unsafe links that came from a person or a model', function () {
        $version = makeVersion($this->report, [
            ['key' => 'overview', 'title' => 'Overview', 'markdown' => "Hello <script>alert(1)</script> <img src=x onerror=alert(2)>\n\n[click](javascript:alert(3)) [ok](https://example.org)\n\n<iframe src=\"//evil\"></iframe>", 'fallback' => false],
        ]);

        $html = app(ReportHtml::class)->render($version);

        expect($html)->not->toContain('<script')->not->toContain('onerror')->not->toContain('javascript:')->not->toContain('<iframe')
            ->and($html)->toContain('https://example.org');
    });

    it('escapes the title', function () {
        $version = makeVersion($this->report);
        $version->content = ['title' => 'A <b>bold</b> & "quoted" title'] + $version->content;
        $version->save();

        expect(app(ReportHtml::class)->render($version->fresh()))->toContain('A &lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot; title')->not->toContain('<b>bold</b>');
    });

    it('has a footer with the page numbers Gotenberg fills in', function () {
        expect(app(ReportHtml::class)->footer($this->version))->toContain('class="pageNumber"')->toContain('class="totalPages"')->toContain('Harbor Portal Monthly Report');
    });
});

describe('files', function () {
    it('stores the .md and the PDF together, privately, with checksums', function () {
        expect(app(ReportFiles::class)->ensure($this->version))->toBeTrue();

        $files = ReportFile::query()->where('report_version_id', $this->version->id)->get()->keyBy(fn ($f) => $f->format->value);
        $disk = Storage::disk('reports');

        expect($files->keys()->sort()->values()->all())->toBe(['md', 'pdf'])
            ->and($files['md']->file_path)->toBe($this->user->id.'/'.$this->report->id.'/v1.md')
            ->and($disk->get($files['md']->file_path))->toBe(app(ReportMarkdown::class)->build($this->version))
            ->and(str_starts_with($disk->get($files['pdf']->file_path), '%PDF-'))->toBeTrue()
            ->and($files['pdf']->checksum)->toBe(hash('sha256', $disk->get($files['pdf']->file_path)))
            ->and($files['md']->checksum)->toBe(hash('sha256', $disk->get($files['md']->file_path)));
    });

    it('feeds the PDF engine exactly the HTML the dashboard previews', function () {
        app(ReportFiles::class)->ensure($this->version);

        expect($this->pdf->rendered)->toHaveCount(1)
            ->and($this->pdf->rendered[0]['html'])->toBe(app(ReportHtml::class)->render($this->version))
            ->and($this->pdf->rendered[0]['footer'])->toBe(app(ReportHtml::class)->footer($this->version));
    });

    it('creates neither file when the PDF cannot be made, and can be retried', function () {
        $this->pdf->failWith(PdfRenderException::unreachable());

        expect(fn () => app(ReportFiles::class)->ensure($this->version))->toThrow(PdfRenderException::class)
            ->and(ReportFile::query()->count())->toBe(0)->and(Storage::disk('reports')->allFiles())->toBe([]);

        $this->app->forgetInstance(PdfRenderer::class);
        $this->app->forgetInstance(ReportFiles::class);
        app(FakePdfRenderer::class);   // a healthy engine again

        expect(app(ReportFiles::class)->ensure($this->version))->toBeTrue()->and(ReportFile::query()->count())->toBe(2);
    });

    it('is idempotent: a version that has both files is not rendered again', function () {
        app(ReportFiles::class)->ensure($this->version);

        expect(app(ReportFiles::class)->ensure($this->version))->toBeFalse()->and($this->pdf->rendered)->toHaveCount(1)->and(ReportFile::query()->count())->toBe(2);
    });

    it('is produced by the queue job, which carries ids only and logs failures as codes', function () {
        RenderReportFiles::dispatchSync($this->version->id, $this->user->id);
        expect(ReportFile::query()->count())->toBe(2);

        $logs = captureLogs();
        (new RenderReportFiles($this->version->id, $this->user->id))->failed(PdfRenderException::failed(500));

        expect(loggedText($logs))->toContain('report.files_failed')->toContain('pdf_engine_status_500')->not->toContain('Shipment Tracking')
            ->and((new RenderReportFiles(5, 6))->queue)->toBe('reports');
    });
});

describe('Gotenberg', function () {
    beforeEach(function () {
        $this->gotenberg = new GotenbergPdfRenderer('http://gotenberg.test:3000/', 30);
    });

    it('posts the document and the footer as multipart files to the Chromium route', function () {
        Http::fake(['gotenberg.test:3000/*' => Http::response("%PDF-1.7\nbody", 200, ['Content-Type' => 'application/pdf'])]);

        $pdf = $this->gotenberg->render('<html>doc</html>', '<html>foot</html>');

        expect($pdf)->toBe("%PDF-1.7\nbody");
        Http::assertSent(function (Request $request) {
            $names = collect($request->data())->pluck('name')->all();
            $files = collect($request->data())->where('name', 'files')->pluck('filename')->all();

            return $request->url() === 'http://gotenberg.test:3000/forms/chromium/convert/html'
                && $request->isMultipart()
                && $files === ['index.html', 'footer.html']
                && in_array('paperWidth', $names, true) && in_array('printBackground', $names, true);
        });
    });

    it('fails with a code, never with the document, on errors and on output that is not a PDF', function () {
        Http::fake(['gotenberg.test:3000/*' => Http::sequence()->push('<html>secret client work</html>', 500)->push('not a pdf', 200)->pushFailedConnection()]);

        foreach (['pdf_engine_status_500', 'pdf_engine_invalid_output', 'pdf_engine_unreachable'] as $code) {
            try {
                $this->gotenberg->render('<html>secret client work</html>', 'f');
                $this->fail('expected a failure');
            } catch (PdfRenderException $e) {
                expect($e->getMessage())->toBe($code);
            }
        }
    });
});

describe('signed downloads', function () {
    beforeEach(function () {
        app(ReportFiles::class)->ensure($this->version);
        $this->pdfFile = ReportFile::query()->where('format', ReportFileFormat::Pdf)->firstOrFail();
        $this->mdFile = ReportFile::query()->where('format', ReportFileFormat::Md)->firstOrFail();
        $this->downloads = app(SignedDownload::class);
    });

    it('serves the file through a signed URL with safe headers', function () {
        $url = $this->downloads->url($this->pdfFile->id, $this->user);

        $response = $this->get($url)->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('harbor-portal-monthly-report-september-2026-v1.pdf')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');

        $md = $this->get($this->downloads->url($this->mdFile->id, $this->user))->assertOk();
        expect($md->headers->get('Content-Type'))->toContain('text/markdown');
    });

    it('is refused without a signature, with a changed signature or parameter, and after it expires', function () {
        $url = $this->downloads->url($this->pdfFile->id, $this->user);
        $other = $this->mdFile->id;

        $this->get('/reports/files/'.$this->pdfFile->id)->assertForbidden();
        $this->get(str_replace('/files/'.$this->pdfFile->id, '/files/'.$other, $url))->assertForbidden();
        $this->get(preg_replace('/u=\d+/', 'u=999', $url))->assertForbidden();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=deadbeef', $url))->assertForbidden();

        Carbon::setTestNow(now()->addMinutes((int) config('reports.download_ttl_minutes') + 1));
        $this->get($url)->assertForbidden();
    });

    it('does not give another user a URL for a file, nor serve it to them', function () {
        $stranger = User::factory()->create(['telegram_user_id' => 888001]);

        expect(asUser($stranger->id, fn () => $this->downloads->url($this->pdfFile->id, $stranger)))->toBeNull()
            ->and($this->downloads->response($this->pdfFile->id, $stranger->id))->toBeNull();
    });

    it('answers 404 when the file is gone from the disk or the row does not exist', function () {
        $url = $this->downloads->url($this->pdfFile->id, $this->user);
        Storage::disk('reports')->delete($this->pdfFile->file_path);

        $this->get($url)->assertNotFound();
        expect($this->downloads->url(999999, $this->user))->toBeNull();
    });

    it('is HTTPS-only in production', function () {
        $url = $this->downloads->url($this->pdfFile->id, $this->user);

        withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'http', 'AI_PROVIDER' => 'deepseek', 'PDF_RENDERER' => 'gotenberg'], function () use ($url) {
            $this->get(str_replace('https://', 'http://', $url))->assertNotFound();
        });
    });
});
