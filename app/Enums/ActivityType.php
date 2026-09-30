<?php

namespace App\Enums;

use App\Enums\Concerns\EnumValues;

/**
 * Activity categories (PRD §15).
 */
enum ActivityType: string
{
    use EnumValues;

    case Request = 'request';
    case Investigation = 'investigation';
    case Development = 'development';
    case Configuration = 'configuration';
    case BugFix = 'bug_fix';
    case Testing = 'testing';
    case Deployment = 'deployment';
    case Communication = 'communication';
    case Research = 'research';
    case Documentation = 'documentation';
    case Milestone = 'milestone';
    case Blocker = 'blocker';
    case Resolution = 'resolution';
    case FollowUp = 'follow_up';
    case Other = 'other';
}
