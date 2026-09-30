<?php

namespace App\Services\Worklog;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Project creation shared by every channel (CLAUDE.md rule 2). Requires a UserContext:
 * Project is user-scoped and takes its user_id from it.
 */
class ProjectService
{
    public const NAME_MAX_LENGTH = 80;

    /**
     * Normalises a raw project name; null when it cannot be used (empty, too long, no letters/digits).
     */
    public function normalizeName(string $name): ?string
    {
        $firstLine = trim(explode("\n", trim($name))[0]);
        $clean = trim((string) preg_replace('/\s+/u', ' ', $firstLine));

        if ($clean === '' || mb_strlen($clean) > self::NAME_MAX_LENGTH || Str::slug($clean) === '') {
            return null;
        }

        return $clean;
    }

    /**
     * @return array{0: Project, 1: bool} the project and whether it was newly created
     */
    public function findOrCreate(string $name): array
    {
        $slug = Str::slug($name);

        $existing = Project::query()->where('slug', $slug)->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $project = Project::query()->create([
            'name' => $name,
            'slug' => $slug,
            'status' => ProjectStatus::Active,
        ]);

        return [$project, true];
    }
}
