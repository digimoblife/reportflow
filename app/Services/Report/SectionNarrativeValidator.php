<?php

namespace App\Services\Report;

use App\Services\Ai\Schema\JsonSchemaValidator;

/**
 * Traceability check on an AI narrative (PRD §44: "backend memeriksa bahwa setiap task yang disebut berasal dari data
 * periode"). Schema first, then the backend's own rules. Error codes never carry the model's text. A narrative is
 * accepted only when every task it cites is in the section's data, every number it states appears in that data, and it
 * adds no structure (headings, tables, HTML, links) of its own.
 */
class SectionNarrativeValidator
{
    private ?JsonSchemaValidator $schema = null;

    /**
     * @param  array<mixed>  $decoded
     * @return list<string>
     */
    public function errors(array $decoded, ReportSectionPayload $payload): array
    {
        $this->schema ??= JsonSchemaValidator::fromFile(resource_path('schemas/report_section.v1.json'));
        $errors = $this->schema->validate($decoded);

        if ($errors !== []) {
            return $errors;
        }

        $markdown = (string) $decoded['markdown'];
        $allowed = array_flip($payload->taskIds());
        $declared = array_map('intval', (array) $decoded['used_task_ids']);

        foreach ($declared as $id) {
            if (! isset($allowed[$id])) {
                $errors[] = 'used_task_ids:not_in_section_data';
                break;
            }
        }

        preg_match_all('/\{\{task:(\d+)\}\}/', $markdown, $tokens);

        foreach ($tokens[1] as $id) {
            if (! isset($allowed[(int) $id])) {
                $errors[] = 'markdown:unknown_task_token';
                break;
            }

            if (! in_array((int) $id, $declared, true)) {
                $errors[] = 'markdown:token_not_declared';
                break;
            }
        }

        if (preg_match('/^\s{0,3}(#|\||```)/m', $markdown) === 1 || preg_match('/[<>]|https?:\/\/|www\.|\]\(/i', $markdown) === 1) {
            $errors[] = 'markdown:forbidden_structure';
        }

        $pool = array_flip($payload->numberPool);

        foreach (ReportSectionPayload::numbers($markdown) as $number) {
            if (! isset($pool[$number])) {
                $errors[] = 'markdown:number_not_in_data';
                break;
            }
        }

        return $errors;
    }

    /**
     * The narrative with every {{task:ID}} replaced by that task's real (escaped) title. Call only after errors() was empty.
     *
     * @param  array<mixed>  $decoded
     */
    public function render(array $decoded, ReportSectionPayload $payload, ReportFactsBuilder $facts): string
    {
        $markdown = trim((string) $decoded['markdown']);

        return (string) preg_replace_callback('/\{\{task:(\d+)\}\}/', fn (array $m): string => '**'.$facts->escape($payload->titles[(int) $m[1]] ?? '').'**', $markdown);
    }
}
