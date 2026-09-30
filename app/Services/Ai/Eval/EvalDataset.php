<?php

namespace App\Services\Ai\Eval;

use InvalidArgumentException;
use JsonException;

/**
 * An evaluation dataset (PRD §75): a snapshot of task state plus labelled messages.
 *
 * Directory layout: snapshot.json and cases.jsonl (one JSON object per line). See
 * docs/runbooks/evaluation-dataset.md for the label format.
 */
final readonly class EvalDataset
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<array<string, mixed>>  $cases
     */
    public function __construct(
        public string $name,
        public array $snapshot,
        public array $cases,
    ) {}

    /**
     * A subset for quick runs: cases having ANY of the categories and/or listed ids, then the first $limit.
     *
     * @param  list<string>  $categories
     * @param  list<string>  $ids
     */
    public function filter(array $categories = [], array $ids = [], ?int $limit = null): self
    {
        $cases = array_values(array_filter($this->cases, function (array $case) use ($categories, $ids): bool {
            $byCategory = $categories === [] || array_intersect($categories, $case['categories']) !== [];
            $byId = $ids === [] || in_array($case['id'], $ids, true);

            return $byCategory && $byId;
        }));

        if ($limit !== null) {
            $cases = array_slice($cases, 0, max(0, $limit));
        }

        return new self($this->name, $this->snapshot, $cases);
    }

    public static function load(string $directory, string $name): self
    {
        $snapshotFile = $directory.'/snapshot.json';
        $casesFile = $directory.'/cases.jsonl';

        if (! is_file($snapshotFile) || ! is_file($casesFile)) {
            throw new InvalidArgumentException("Dataset [{$name}] not found: expected snapshot.json and cases.jsonl in that directory.");
        }

        try {
            $snapshot = json_decode((string) file_get_contents($snapshotFile), true, 512, JSON_THROW_ON_ERROR);
            $cases = [];

            foreach (preg_split('/\R/', trim((string) file_get_contents($casesFile))) ?: [] as $number => $line) {
                if (trim($line) === '') {
                    continue;
                }

                $case = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                self::assertCase($case, $number + 1);
                $cases[] = $case;
            }
        } catch (JsonException) {
            // Never echo the offending line: it may be real client data.
            throw new InvalidArgumentException("Dataset [{$name}] contains invalid JSON.");
        }

        $ids = array_column($cases, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException("Dataset [{$name}] has duplicate case ids.");
        }

        return new self($name, is_array($snapshot) ? $snapshot : [], $cases);
    }

    private static function assertCase(mixed $case, int $line): void
    {
        if (! is_array($case) || ! is_string($case['id'] ?? null) || ! is_string($case['message'] ?? null)
            || ! is_array($case['expected']['items'] ?? null) || ! is_array($case['categories'] ?? null)) {
            throw new InvalidArgumentException("Dataset case on line {$line} needs id, message, categories and expected.items.");
        }
    }
}
