<?php

namespace App\Services\Scrum;

/**
 * Parses daily scrum text format (PRD Scrum §4.2):
 *
 * [Name]
 * What Have Done
 * - item 1
 * Issue
 * - item 2
 * What To Do Next
 * - item 3
 */
class ScrumTextParser
{
    /**
     * Checks if a given text appears to be in Scrum Standup format.
     */
    public function isScrumFormat(string $text): bool
    {
        $hasDone = (bool) preg_match('/what\s+(have\s+)?(been\s+)?done|sudah\s+dikerjakan|selesai/i', $text);
        $hasNext = (bool) preg_match('/what\s+to\s+do\s+next|rencana\s+(selanjutnya|hari\s+ini)|what\s+next/i', $text);

        return $hasDone && $hasNext;
    }

    /**
     * Parse multi-member or single-member scrum text.
     *
     * @return list<array{
     *     name: string,
     *     what_have_done: string|null,
     *     issue: string|null,
     *     what_to_do_next: string|null
     * }>
     */
    public function parse(string $text): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($text));
        if ($lines === false || $lines === []) {
            return [];
        }

        $results = [];
        $currentName = '';
        $currentSection = null; // 'done' | 'issue' | 'next'
        /** @var list<string> $doneLines */
        $doneLines = [];
        /** @var list<string> $issueLines */
        $issueLines = [];
        /** @var list<string> $nextLines */
        $nextLines = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            // Check section headers
            if (preg_match('/^(what\s+(have\s+)?(been\s+)?done|sudah\s+dikerjakan|selesai)[:\s]*$/i', $line) === 1) {
                $currentSection = 'done';

                continue;
            }

            if (preg_match('/^(issue|issues|kendala|blocker|hambatan)[:\s]*$/i', $line) === 1) {
                $currentSection = 'issue';

                continue;
            }

            if (preg_match('/^(what\s+to\s+do\s+next|rencana\s+(selanjutnya|hari\s+ini)|what\s+next|to\s+do)[:\s]*$/i', $line) === 1) {
                $currentSection = 'next';

                continue;
            }

            // If we don't have a section yet, this might be a person's name or new person header
            if ($currentSection === null) {
                // If it doesn't look like a bullet point, it's a name
                if (! str_starts_with($line, '-') && ! str_starts_with($line, '•') && ! str_starts_with($line, '*')) {
                    $currentName = $line;

                    continue;
                }
            } else {
                // If we encounter a new name header after already having parsed content:
                // A new name header usually has no bullets and is followed later by a section header.
                if (! str_starts_with($line, '-') && ! str_starts_with($line, '•') && ! str_starts_with($line, '*')
                    && preg_match('/^[A-Z][a-zA-Z0-9\s]{1,30}$/', $line) === 1 && $currentSection === 'next') {
                    $entry = $this->buildEntry($currentName, $doneLines, $issueLines, $nextLines);
                    if ($entry !== null) {
                        $results[] = $entry;
                    }
                    $currentName = $line;
                    $currentSection = null;
                    $doneLines = [];
                    $issueLines = [];
                    $nextLines = [];

                    continue;
                }
            }

            // Append to current section
            match ($currentSection) {
                'done' => $doneLines[] = $line,
                'issue' => $issueLines[] = $line,
                'next' => $nextLines[] = $line,
                default => null,
            };
        }

        $entry = $this->buildEntry($currentName, $doneLines, $issueLines, $nextLines);
        if ($entry !== null) {
            $results[] = $entry;
        }

        return $results;
    }

    /**
     * @param  list<string>  $doneLines
     * @param  list<string>  $issueLines
     * @param  list<string>  $nextLines
     * @return array{name: string, what_have_done: string|null, issue: string|null, what_to_do_next: string|null}|null
     */
    private function buildEntry(string $name, array $doneLines, array $issueLines, array $nextLines): ?array
    {
        if (trim($name) === '' && $doneLines === [] && $nextLines === []) {
            return null;
        }

        return [
            'name' => trim($name),
            'what_have_done' => $doneLines !== [] ? implode("\n", $doneLines) : null,
            'issue' => $issueLines !== [] ? implode("\n", $issueLines) : null,
            'what_to_do_next' => $nextLines !== [] ? implode("\n", $nextLines) : null,
        ];
    }
}
