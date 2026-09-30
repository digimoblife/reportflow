<?php

namespace App\Services\Redaction;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Last line of defence: every log record passes through redaction before any handler sees it.
 * Application code must still never log message text (CLAUDE.md); this catches mistakes and
 * secrets that arrive through framework messages, SQL bindings or exception traces.
 *
 * Throwables in the context are flattened to redacted strings, so no handler can render their
 * raw message or argument-carrying trace.
 */
final class RedactingLogProcessor implements ProcessorInterface
{
    private readonly RedactionService $redaction;

    public function __construct(?RedactionService $redaction = null)
    {
        // Traces can be long; the limit is higher than for chat messages.
        $this->redaction = $redaction ?? new RedactionService([], 1_000_000);
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        try {
            return $record->with(
                message: $this->clean($record->message),
                context: $this->cleanArray($record->context),
                extra: $this->cleanArray($record->extra),
            );
        } catch (Throwable) {
            return $record->with(message: '[log record withheld: redaction failed]', context: [], extra: []);
        }
    }

    private function clean(string $value): string
    {
        return $this->redaction->redact($value)->text;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function cleanArray(array $data, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['[max depth]'];
        }

        foreach ($data as $key => $value) {
            $data[$key] = match (true) {
                is_string($value) => $this->clean($value),
                $value instanceof Throwable => [
                    'class' => $value::class,
                    'message' => $this->clean($value->getMessage()),
                    'code' => $value->getCode(),
                    'file' => $value->getFile().':'.$value->getLine(),
                    'trace' => $this->clean($value->getTraceAsString()),
                ],
                is_array($value) => $this->cleanArray($value, $depth + 1),
                is_scalar($value) || $value === null => $value,
                // Objects are logged by class only; their properties may hold raw text.
                default => '['.get_debug_type($value).']',
            };
        }

        return $data;
    }
}
