<?php

namespace App\Services\Report\Pdf;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gotenberg's Chromium route (`/forms/chromium/convert/html`). A4 with the page margins of the template; the footer is a
 * separate HTML file Gotenberg repeats on every page. The Gotenberg container is on the internal Docker network only.
 */
class GotenbergPdfRenderer implements PdfRenderer
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
    ) {}

    public function render(string $html, string $footerHtml): string
    {
        try {
            $response = Http::timeout($this->timeout)->connectTimeout(5)
                ->attach('files', $html, 'index.html')
                ->attach('files', $footerHtml, 'footer.html')
                ->post(rtrim($this->baseUrl, '/').'/forms/chromium/convert/html', [
                    'paperWidth' => '8.27',
                    'paperHeight' => '11.69',
                    'marginTop' => '0.8',
                    'marginBottom' => '0.9',
                    'marginLeft' => '0.8',
                    'marginRight' => '0.8',
                    'printBackground' => 'true',
                    'preferCssPageSize' => 'false',
                ]);
        } catch (ConnectionException) {
            throw PdfRenderException::unreachable();
        }

        if (! $response->successful()) {
            throw PdfRenderException::failed($response->status());
        }

        $body = $response->body();

        if (! str_starts_with($body, '%PDF-')) {
            throw PdfRenderException::invalidOutput();
        }

        return $body;
    }
}
