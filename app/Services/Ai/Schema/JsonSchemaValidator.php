<?php

namespace App\Services\Ai\Schema;

/**
 * A small JSON Schema validator covering exactly the keywords our schemas use (no dependency, per the
 * M3 decision): type (string or list), enum, required, properties, additionalProperties (false),
 * items, minItems/maxItems, minimum/maximum, minLength/maxLength, pattern, oneOf.
 *
 * Errors carry a JSON path and a keyword code only, never the offending value: model output may
 * echo user text and must not end up in logs or the `error` column.
 */
final class JsonSchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(private readonly array $schema) {}

    public static function fromFile(string $path): self
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return new self(is_array($decoded) ? $decoded : []);
    }

    /**
     * @return list<string> error codes like "$.items[0].confidence:maximum"; empty when valid
     */
    public function validate(mixed $data): array
    {
        $errors = [];
        $this->check($data, $this->schema, '$', $errors);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['oneOf'])) {
            $matches = 0;

            foreach ($schema['oneOf'] as $branch) {
                $branchErrors = [];
                $this->check($value, $branch, $path, $branchErrors);
                $matches += $branchErrors === [] ? 1 : 0;
            }

            if ($matches !== 1) {
                $errors[] = "{$path}:oneOf";
            }

            return;
        }

        if (isset($schema['type']) && ! $this->typeMatches($value, array_values(array_map('strval', (array) $schema['type'])))) {
            $errors[] = "{$path}:type";

            return;
        }

        if (array_key_exists('enum', $schema) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}:enum";
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$path}:minimum";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = "{$path}:maximum";
            }
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                $errors[] = "{$path}:minLength";
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                $errors[] = "{$path}:maxLength";
            }
            if (isset($schema['pattern']) && preg_match('~'.str_replace('~', '\~', $schema['pattern']).'~u', $value) !== 1) {
                $errors[] = "{$path}:pattern";
            }
        }

        if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
            $count = count($value);
            if (isset($schema['minItems']) && $count < $schema['minItems']) {
                $errors[] = "{$path}:minItems";
            }
            if (isset($schema['maxItems']) && $count > $schema['maxItems']) {
                $errors[] = "{$path}:maxItems";
            }
            foreach ($value as $i => $item) {
                $this->check($item, $schema['items'], "{$path}[{$i}]", $errors);
            }
        }

        if (is_array($value) && ! array_is_list($value) || $value === [] && ($schema['type'] ?? null) === 'object') {
            $this->checkObject($value, $schema, $path, $errors);
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private function checkObject(array $value, array $schema, string $path, array &$errors): void
    {
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                $errors[] = "{$path}.{$key}:required";
            }
        }

        $properties = $schema['properties'] ?? [];

        foreach ($value as $key => $item) {
            if (isset($properties[$key])) {
                $this->check($item, $properties[$key], "{$path}.{$key}", $errors);
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = "{$path}.{$key}:additionalProperties";
            }
        }
    }

    /**
     * @param  list<string>  $types
     */
    private function typeMatches(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $ok = match ($type) {
                'null' => $value === null,
                'string' => is_string($value),
                'integer' => is_int($value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'array' => is_array($value) && array_is_list($value),
                'object' => is_array($value) && (! array_is_list($value) || $value === []),
                default => false,
            };

            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
