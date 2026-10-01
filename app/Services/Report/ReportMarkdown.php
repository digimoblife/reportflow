<?php

namespace App\Services\Report;

use App\Models\ReportVersion;

/**
 * The Markdown file of a version (PRD §64): title, then every section with its heading. This is the intermediate
 * format the HTML and the PDF are made from, and it is always delivered together with the PDF.
 */
class ReportMarkdown
{
    public function build(ReportVersion $version): string
    {
        $content = $version->content;
        $parts = ['# '.$this->line((string) ($content['title'] ?? ''))];

        foreach ((array) ($content['sections'] ?? []) as $section) {
            $parts[] = '## '.$this->line((string) $section['title'])."\n\n".trim((string) $section['markdown']);
        }

        return implode("\n\n", $parts)."\n";
    }

    private function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
