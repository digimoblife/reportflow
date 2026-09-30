<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * How a report version was produced (PRD §49 report_versions).
 */
enum ReportCreatedBy: string
{
    use EnumValues;

    case AiGenerate = 'ai_generate';
    case InstructionEdit = 'instruction_edit';
    case UserEdit = 'user_edit';
}
