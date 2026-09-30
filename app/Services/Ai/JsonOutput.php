<?php

namespace App\Services\Ai;

/**
 * Tolerant JSON extraction from a model reply: plain JSON, ```json fences, or JSON surrounded by prose.
 */
final class JsonOutput
{
    /**
     * @return array<mixed>|null null when no JSON object/array can be decoded
     */
    public static function decode(string $content): ?array
    {
        $content = trim($content);

        if ($content === '') {
            return null;
        }

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/su', $content, $m) === 1) {
            $content = $m[1];
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');

            if ($start === false || $end === false || $end <= $start) {
                return null;
            }

            $decoded = json_decode(substr($content, $start, $end - $start + 1), true);
        }

        return is_array($decoded) ? $decoded : null;
    }
}
