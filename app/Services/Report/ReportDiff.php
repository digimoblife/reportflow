<?php

namespace App\Services\Report;

use App\Models\ReportVersion;

/**
 * A plain line diff between two versions, section by section (PRD §42 "perbandingan versi"), without a dependency.
 * Lines are compared exactly; the output is for display only.
 */
class ReportDiff
{
    /**
     * @return list<array{key: string, title: string, state: string, lines: list<array{type: string, text: string}>}>
     */
    public function compare(ReportVersion $from, ReportVersion $to): array
    {
        $a = $this->sections($from);
        $b = $this->sections($to);
        $keys = array_values(array_unique([...array_keys($a), ...array_keys($b)]));
        $out = [];

        foreach ($keys as $key) {
            $old = $a[$key] ?? null;
            $new = $b[$key] ?? null;
            $lines = $this->lines($old['markdown'] ?? '', $new['markdown'] ?? '');
            $state = match (true) {
                $old === null => 'added',
                $new === null => 'removed',
                array_filter($lines, fn (array $l): bool => $l['type'] !== 'same') === [] => 'same',
                default => 'changed',
            };

            $out[] = ['key' => $key, 'title' => (string) ($new['title'] ?? $old['title'] ?? $key), 'state' => $state, 'lines' => $lines];
        }

        return $out;
    }

    /**
     * @return array<string, array{title: string, markdown: string}>
     */
    private function sections(ReportVersion $version): array
    {
        $sections = [];

        foreach ((array) ($version->content['sections'] ?? []) as $section) {
            $sections[(string) $section['key']] = ['title' => (string) $section['title'], 'markdown' => (string) $section['markdown']];
        }

        return $sections;
    }

    /**
     * Longest-common-subsequence diff of two texts by line.
     *
     * @return list<array{type: string, text: string}>
     */
    private function lines(string $old, string $new): array
    {
        $a = $old === '' ? [] : explode("\n", $old);
        $b = $new === '' ? [] : explode("\n", $new);
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = [];
        [$i, $j] = [0, 0];

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['type' => 'same', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['type' => 'del', 'text' => $a[$i++]];
            } else {
                $out[] = ['type' => 'add', 'text' => $b[$j++]];
            }
        }

        for (; $i < $n; $i++) {
            $out[] = ['type' => 'del', 'text' => $a[$i]];
        }

        for (; $j < $m; $j++) {
            $out[] = ['type' => 'add', 'text' => $b[$j]];
        }

        return $out;
    }
}
