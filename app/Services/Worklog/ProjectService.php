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

    /**
     * @return bool false when the name is unusable or another project of the user already has that slug
     */
    public function rename(Project $project, string $name): bool
    {
        $clean = $this->normalizeName($name);

        if ($clean === null) {
            return false;
        }

        $slug = Str::slug($clean);

        if (Project::query()->where('slug', $slug)->where('id', '!=', $project->id)->exists()) {
            return false;
        }

        $project->update(['name' => $clean, 'slug' => $slug]);

        return true;
    }

    /**
     * Aliases help the assistant recognise the project in a note: trimmed, unique (case-insensitive), at most 10.
     *
     * @param  array<array-key, mixed>  $aliases
     * @return list<string> what was stored
     */
    public function setAliases(Project $project, array $aliases): array
    {
        $clean = [];

        foreach ($aliases as $alias) {
            $alias = trim((string) preg_replace('/\s+/u', ' ', is_scalar($alias) ? (string) $alias : ''));

            if ($alias !== '' && mb_strlen($alias) <= 40 && ! in_array(mb_strtolower($alias), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $alias;
            }
        }

        $clean = array_slice($clean, 0, 10);
        $project->update(['aliases' => $clean]);

        return $clean;
    }

    /**
     * Archived projects stay in the database with their tasks and history; they just stop being offered to the assistant.
     */
    public function setActive(Project $project, bool $active): void
    {
        $project->update(['status' => $active ? ProjectStatus::Active : ProjectStatus::Archived]);
    }
}
