<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Generated report file formats (PRD §49 report_files). DOCX is Phase 3.
 */
enum ReportFileFormat: string
{
    use EnumValues;

    case Pdf = 'pdf';
    case Md = 'md';
    case Docx = 'docx';
}
