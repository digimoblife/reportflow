<?php

namespace App\Services\Redaction;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Removes credentials from text before it is stored, logged or sent to an AI provider
 * (PRD §56, CLAUDE.md rules 6 and 7). Detected values are replaced with [REDACTED_SECRET].
 *
 * Safety properties:
 * - Idempotent: the placeholder never matches a rule, so redact(redact(x)) === redact(x).
 * - Fail closed: any PCRE error, invalid UTF-8 or over-long input replaces the WHOLE text with the
 *   placeholder and reports category `redaction_error`. Callers must then not process the message.
 * - Bounded: every quantifier has an upper bound, pcre.backtrack_limit is capped while redacting and
 *   the input length is limited (see MAX_INPUT_LENGTH).
 * - No generic entropy detection (too many false positives on hashes/UUIDs); only known patterns.
 */
final class RedactionService
{
    public const PLACEHOLDER = '[REDACTED_SECRET]';

    /** Characters. Telegram messages are at most 4096; longer input fails closed. */
    public const MAX_INPUT_LENGTH = 50_000;

    /** Applied through ini_set for the duration of one redact() call. */
    public const BACKTRACK_LIMIT = 200_000;

    /** A value that "looks like a secret": >= 6 chars with a letter and a digit or symbol. */
    private const SECRET_LOOKAHEAD = '(?=[^\s\'";,]{6,})(?=[^\s\'";,]*[A-Za-z])(?=[^\s\'";,]*[0-9!@#$%^&*+=])';

    /** @var list<array{pattern: string, category: RedactionCategory}> */
    private array $rules;

    /**
     * @param  list<string>  $customPatterns  full PCRE patterns (with delimiters); the whole match is replaced
     */
    public function __construct(array $customPatterns = [], private readonly int $maxInputLength = self::MAX_INPUT_LENGTH)
    {
        $this->rules = [
            // 1. Private keys, including an unterminated block (everything to the end).
            $this->rule('~-----BEGIN [A-Z0-9 ]{0,40}PRIVATE KEY(?: BLOCK)?-----(?:.*?-----END [A-Z0-9 ]{0,40}PRIVATE KEY(?: BLOCK)?-----|.*+)~su', RedactionCategory::PrivateKey),

            // 2. Database/broker URIs with credentials, then userinfo passwords in other URLs.
            $this->rule('~\b(?:postgres(?:ql)?|mysql|mariadb|mongodb(?:\+srv)?|redis|rediss|amqps?|sqlserver|mssql)://[^\s/@:\'"]{0,128}:[^\s@\'"]{1,256}@[^\s\'"]{1,1024}~iu', RedactionCategory::ConnectionString),
            $this->rule('~(?<=://)[^\s/@:\'"]{1,128}:\K(?!\[REDACTED_SECRET\])[^\s/@\'"]{1,256}(?=@)~u', RedactionCategory::ConnectionString),

            // 3. Well-known token formats. The lookbehind stops "task-…" or "disk-…" from matching "sk-".
            $this->rule('~(?<![A-Za-z0-9_-])sk-[A-Za-z0-9_-]{20,200}~u', RedactionCategory::ApiKey),
            $this->rule('~(?<![A-Za-z0-9_-])[sr]k_(?:live|test)_[A-Za-z0-9]{16,200}~u', RedactionCategory::ApiKey),
            $this->rule('~(?<![A-Za-z0-9_-])gh[pousr]_[A-Za-z0-9]{36,255}~u', RedactionCategory::ApiKey),
            $this->rule('~(?<![A-Za-z0-9_-])github_pat_[A-Za-z0-9_]{22,255}~u', RedactionCategory::ApiKey),
            $this->rule('~(?<![A-Za-z0-9_-])glpat-[A-Za-z0-9_-]{20,200}~u', RedactionCategory::ApiKey),
            $this->rule('~(?<![A-Za-z0-9])(?:AKIA|ASIA)[0-9A-Z]{16}(?![A-Za-z0-9])~u', RedactionCategory::ApiKey),
            $this->rule('~(?<![A-Za-z0-9_-])AIza[0-9A-Za-z_-]{35}~u', RedactionCategory::ApiKey),
            $this->rule('~(?<![A-Za-z0-9_-])xox[abprs]-[A-Za-z0-9-]{10,200}~u', RedactionCategory::ApiKey),
            $this->rule('~(?<![0-9A-Za-z])[0-9]{8,10}:[A-Za-z0-9_-]{35}(?![A-Za-z0-9_-])~u', RedactionCategory::ApiKey),
            $this->rule('~(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]{10,2000}\.eyJ[A-Za-z0-9_-]{10,2000}\.[A-Za-z0-9_-]{5,2000}~u', RedactionCategory::ApiKey),
            $this->rule('~\bbearer[ \t]+\K(?!\[REDACTED_SECRET\])[A-Za-z0-9._\~+/=-]{20,1000}~iu', RedactionCategory::ApiKey),

            // 4. Env-style assignments: only the value is replaced, the variable name stays readable.
            $this->rule('~(?<![A-Za-z0-9_])[A-Z][A-Z0-9_]{0,60}(?:SECRET|TOKEN|PASSWORD|PASSWD|API_?KEY|PRIVATE_KEY|ACCESS_KEY|CREDENTIALS?)[A-Z0-9_]{0,60}[ \t]{0,4}=[ \t]{0,4}\K(?!\[REDACTED_SECRET\])(?:"[^"\r\n]{1,512}"|\'[^\'\r\n]{1,512}\'|[^\s"\']{1,512})~u', RedactionCategory::Password),

            // 5. Password keywords. Strong keywords with ":" or "=" always redact the value that follows
            //    (also JSON/quoted forms). Weak keywords (pass, pwd, pw) need a secret-looking value.
            $this->rule('~(?<![A-Za-z0-9_])(?:password|passwd|passphrase|kata[ \t]?sandi|katasandi|sandi)(?:nya)?["\']?[ \t]{0,4}(?::|=|->)[ \t]{0,4}\K(?!\[REDACTED_SECRET\])(?:"[^"\r\n]{1,256}"|\'[^\'\r\n]{1,256}\'|[^\s\'";,]{1,256})~iu', RedactionCategory::Password),
            $this->rule('~(?<![A-Za-z0-9_])(?:pass|pwd|pw)["\']?[ \t]{0,4}[:=][ \t]{0,4}\K(?!\[REDACTED_SECRET\])(?:"[^"\r\n]{1,256}"|\'[^\'\r\n]{1,256}\'|(?=[^\s\'";,]{6,256})(?=[^\s\'";,]*[A-Za-z])(?=[^\s\'";,]*[0-9!@#$%^&*+=])[^\s\'";,]+)~iu', RedactionCategory::Password),
            // Word forms: "password is X", "passwordnya X", "kata sandi adalah X" — only for secret-looking X.
            $this->rule('~(?<![A-Za-z0-9_])(?:password|passwd|passphrase|kata[ \t]?sandi|katasandi|sandi)(?:nya[ \t]{1,4}|[ \t]{1,4}(?:is|adalah|yaitu|itu)[ \t]{1,4})\K(?!\[REDACTED_SECRET\])'.self::SECRET_LOOKAHEAD.'[^\s\'";,]{1,256}~iu', RedactionCategory::Password),
        ];

        foreach ($customPatterns as $pattern) {
            $this->rules[] = $this->rule($pattern, RedactionCategory::Custom);
        }
    }

    /**
     * Build a service from config('redaction') for the given user (global + per-user patterns).
     */
    public static function forUser(?int $userId = null): self
    {
        /** @var list<string> $global */
        $global = config('redaction.extra_patterns', []);
        /** @var list<string> $perUser */
        $perUser = $userId === null ? [] : config("redaction.user_patterns.{$userId}", []);

        return new self([...$global, ...$perUser], (int) config('redaction.max_input_length', self::MAX_INPUT_LENGTH));
    }

    public function redact(#[SensitiveParameter] string $text): RedactionResult
    {
        if (mb_strlen($text) > $this->maxInputLength || ! mb_check_encoding($text, 'UTF-8')) {
            return $this->failClosed();
        }

        $previousLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT);

        try {
            $counts = [];

            foreach ($this->rules as $rule) {
                $replaced = preg_replace($rule['pattern'], self::PLACEHOLDER, $text, -1, $count);

                // null = PCRE error (backtrack/JIT stack limit, bad UTF-8): never return a half-checked text.
                if ($replaced === null) {
                    return $this->failClosed();
                }

                if ($count > 0) {
                    $text = $replaced;
                    $counts[$rule['category']->value] = ($counts[$rule['category']->value] ?? 0) + $count;
                }
            }

            return new RedactionResult($text, $counts);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
        }
    }

    private function failClosed(): RedactionResult
    {
        return new RedactionResult(self::PLACEHOLDER, [RedactionCategory::Error->value => 1]);
    }

    /**
     * @return array{pattern: string, category: RedactionCategory}
     */
    private function rule(string $pattern, RedactionCategory $category): array
    {
        // A broken pattern is a configuration bug: fail loudly at construction, not silently at runtime.
        set_error_handler(static fn (): bool => true);

        try {
            $valid = preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (! $valid) {
            throw new InvalidArgumentException('Invalid redaction pattern for category '.$category->value.': '.preg_last_error_msg());
        }

        return ['pattern' => $pattern, 'category' => $category];
    }
}
