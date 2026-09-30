<?php

namespace App\Services\Ai;

use InvalidArgumentException;

/**
 * Versioned prompt files: resources/prompts/<name>/v<N>.md (CLAUDE.md). A prompt is referenced as
 * "<name>@v<N>", which is also the `prompt_version` stored in ai_interactions. Files are never edited
 * in place: a change is a new version plus an eval run (skill prompt-eval).
 */
class PromptRepository
{
    public function __construct(private readonly ?string $basePath = null) {}

    /**
     * @return array{version: string, name: string, text: string, checksum: string}
     */
    public function load(string $reference): array
    {
        if (preg_match('/^([a-z0-9_]+)@(v[0-9]+)$/', $reference, $m) !== 1) {
            throw new InvalidArgumentException("Prompt reference must look like name@v1, got [{$reference}].");
        }

        $file = ($this->basePath ?? resource_path('prompts')).'/'.$m[1].'/'.$m[2].'.md';

        if (! is_file($file)) {
            throw new InvalidArgumentException("Prompt [{$reference}] does not exist.");
        }

        $text = (string) file_get_contents($file);

        return ['version' => $reference, 'name' => $m[1], 'text' => $text, 'checksum' => hash('sha256', $text)];
    }

    /**
     * @return list<string> known versions of a prompt, e.g. ["worklog_extraction@v1"]
     */
    public function versions(string $name): array
    {
        $files = glob(($this->basePath ?? resource_path('prompts')).'/'.$name.'/v*.md') ?: [];
        $versions = array_map(fn (string $f): string => $name.'@'.basename($f, '.md'), $files);
        natsort($versions);

        return array_values($versions);
    }
}
