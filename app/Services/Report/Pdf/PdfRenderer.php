<?php

namespace App\Services\Report\Pdf;

/**
 * Turns the report HTML into a PDF (PRD §64). The only door to the PDF engine: Gotenberg in dev/production, a fake
 * in tests (TELEGRAM_CLIENT / AI_PROVIDER pattern; "fake" is refused in production).
 */
interface PdfRenderer
{
    /**
     * @param  string  $html  the full document (what the dashboard preview shows)
     * @param  string  $footerHtml  page footer (page numbers); not part of the preview body
     * @return string PDF bytes
     *
     * @throws PdfRenderException
     */
    public function render(string $html, string $footerHtml): string;
}
