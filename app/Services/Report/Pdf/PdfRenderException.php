<?php

namespace App\Services\Report\Pdf;

use RuntimeException;

/**
 * The PDF engine failed. The message is a code only: the document (client work) must not end up in logs.
 */
final class PdfRenderException extends RuntimeException
{
    public static function unreachable(): self
    {
        return new self('pdf_engine_unreachable');
    }

    public static function failed(int $status): self
    {
        return new self('pdf_engine_status_'.$status);
    }

    public static function invalidOutput(): self
    {
        return new self('pdf_engine_invalid_output');
    }
}
