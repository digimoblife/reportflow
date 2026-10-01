<?php

namespace App\Services\Report\Pdf;

/**
 * Test stand-in: records every document it was given and returns a tiny, deterministic "PDF".
 */
final class FakePdfRenderer implements PdfRenderer
{
    /** @var list<array{html: string, footer: string}> */
    public array $rendered = [];

    private ?PdfRenderException $failure = null;

    public function failWith(PdfRenderException $e): self
    {
        $this->failure = $e;

        return $this;
    }

    public function render(string $html, string $footerHtml): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $this->rendered[] = ['html' => $html, 'footer' => $footerHtml];

        return "%PDF-1.4\n% fake pdf\n".sha1($html.$footerHtml)."\n%%EOF\n";
    }
}
