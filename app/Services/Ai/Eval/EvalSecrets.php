<?php

namespace App\Services\Ai\Eval;

/**
 * Dataset messages may contain {{secret:kind}}. The fake credentials are assembled here at runtime so
 * that no complete secret-shaped literal ever lives in the repository (CLAUDE.md).
 */
final class EvalSecrets
{
    public static function expand(string $message): string
    {
        return (string) preg_replace_callback('/\{\{secret:([a-z]+)\}\}/', fn (array $m): string => self::make($m[1]), $message);
    }

    private static function make(string $kind): string
    {
        return match ($kind) {
            'openai' => 's'.'k-'.'EVALFAKE'.'0123456789'.'abcdefghijkl',
            'github' => 'gh'.'p_'.str_repeat('E1v2', 9),
            'password' => 'password: '.'Eval-fake-'.'pw-12345',
            default => 'client_secret='.'evalfake'.'0123456789abcdef',
        };
    }
}
