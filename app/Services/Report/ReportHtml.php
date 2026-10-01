<?php

namespace App\Services\Report;

use App\Models\ReportVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The one HTML of a report version (PRD §40, §64). The dashboard preview and the PDF engine receive exactly this string,
 * which is what makes the preview identical to the PDF. Markdown is converted with raw HTML stripped and unsafe links
 * refused, so nothing a person or a model wrote can inject markup into the document.
 */
class ReportHtml
{
    private ?MarkdownConverter $converter = null;

    public function render(ReportVersion $version, ?string $view = null): string
    {
        $content = $version->content;
        $language = (string) ($content['language'] ?? 'en');
        $sections = [];

        foreach ((array) ($content['sections'] ?? []) as $section) {
            $sections[] = [
                'key' => (string) $section['key'],
                'title' => (string) $section['title'],
                'html' => $this->convert((string) $section['markdown']),
            ];
        }

        /** @var view-string $template */
        $template = $view ?? (string) config('reports.blade_view');

        return View::make($template, [
            'title' => (string) ($content['title'] ?? ''),
            'language' => $language,
            'sections' => $sections,
            'generatedLabel' => (string) Lang::get('report.labels.generated', [], $language),
            'generatedAt' => CarbonImmutable::parse($version->data_snapshot_at)->utc()->format('Y-m-d H:i').' UTC',
            'versionNo' => $version->version_no,
        ])->render();
    }

    /**
     * The footer Gotenberg repeats on every page (title and page numbers).
     */
    public function footer(ReportVersion $version): string
    {
        return '<html><head><style>body{margin:0;font-family:"Liberation Sans",Arial,sans-serif;font-size:8px;color:#555;}'
            .'.f{width:100%;padding:0 0.8in;display:flex;justify-content:space-between;}</style></head><body>'
            .'<div class="f"><span>'.e((string) ($version->content['title'] ?? '')).'</span>'
            .'<span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div></body></html>';
    }

    public function convert(string $markdown): string
    {
        return trim($this->converter()->convert($markdown)->getContent());
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter === null) {
            $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
            $environment->addExtension(new CommonMarkCoreExtension);
            $environment->addExtension(new TableExtension);
            $this->converter = new MarkdownConverter($environment);
        }

        return $this->converter;
    }
}
