<?php

namespace App\Services\Ai;

use App\Services\Ai\Schema\JsonSchemaValidator;

/**
 * The output schema of the worklog extraction prompt (PRD §54), resources/schemas/worklog_extraction.v1.json.
 */
class ExtractionSchema
{
    private ?JsonSchemaValidator $validator = null;

    /**
     * @return list<string> error codes (path:keyword); empty when the data conforms
     */
    public function errors(mixed $data): array
    {
        $this->validator ??= JsonSchemaValidator::fromFile(resource_path('schemas/worklog_extraction.v1.json'));

        return $this->validator->validate($data);
    }
}
