<?php

namespace App\Services\Worklog\Extraction;

enum ConfidenceLevel: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
}
