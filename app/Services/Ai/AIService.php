<?php

namespace App\Services\Ai;

use App\Services\Worklog\CandidateSet;
use Carbon\CarbonImmutable;
use JsonException;

/**
 * The only door from business logic to an AI model (CLAUDE.md, PRD §50). The model proposes; nothing here
 * writes tasks or activities. Every attempt is logged to ai_interactions with its prompt_version.
 *
 * extractWorklog: one call extracts, matches and classifies (PRD §51). If the reply is not valid JSON or
 * does not satisfy the schema it retries ONCE (telling the model which fields failed), then gives up with
 * AiExtractionFailed. Provider errors (timeouts, HTTP errors) are not retried here: the queue job retries.
 */
class AIService
{
    public const PURPOSE_EXTRACTION = 'worklog_extraction';

    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly AiProvider $provider,
        private readonly PromptRepository $prompts,
        private readonly ExtractionSchema $schema,
        private readonly AiInteractionRecorder $recorder,
    ) {}

    /**
     * @param  string  $message  the REDACTED note (inbound_messages.text)
     *
     * @throws AiProviderException
     * @throws AiExtractionFailed
     * @throws JsonException
     */
    public function extractWorklog(
        #[\SensitiveParameter] string $message,
        CandidateSet $candidates,
        CarbonImmutable $today,
        ?int $inboundMessageId = null,
        ?string $promptReference = null,
    ): ExtractionOutcome {
        $prompt = $this->prompts->load($promptReference ?? (string) config('ai.extraction.prompt'));
        $input = $this->input($message, $candidates, $today);

        $projectId = $candidates->detectedProjectIds[0] ?? null;
        $retryCodes = [];
        $tokensIn = 0;
        $tokensOut = 0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $request = $this->buildRequest($message, $candidates, $today, $promptReference, $retryCodes);

            try {
                $response = $this->provider->complete($request);
            } catch (AiProviderException $e) {
                $this->recorder->record(self::PURPOSE_EXTRACTION, $this->provider->name(), $prompt['version'], $input, null, false, 'provider_error', inboundMessageId: $inboundMessageId, projectId: $projectId);

                throw $e;
            }

            $tokensIn += $response->tokensInput ?? 0;
            $tokensOut += $response->tokensOutput ?? 0;

            $decoded = JsonOutput::decode($response->content);
            $codes = $decoded === null
                ? [trim($response->content) === '' ? 'empty_reply' : 'invalid_json']
                : $this->schema->errors($decoded);

            $this->recorder->record(
                self::PURPOSE_EXTRACTION,
                $response->model,
                $prompt['version'],
                $input,
                $response->content,
                $codes === [],
                $codes === [] ? null : implode(',', array_slice($codes, 0, 10)),
                $response->tokensInput,
                $response->tokensOutput,
                $response->latencyMs,
                $inboundMessageId,
                $projectId,
            );

            if ($codes === [] && $decoded !== null) {
                return new ExtractionOutcome($decoded, $prompt['version'], $attempt, $tokensIn, $tokensOut);
            }

            $retryCodes = $codes;
        }

        throw new AiExtractionFailed($retryCodes);
    }

    /**
     * The exact request extractWorklog sends on its first attempt (or on a retry, with the rejected field
     * paths). Public so eval:run can prefetch requests concurrently and hit them by identity.
     *
     * @param  list<string>  $retryCodes
     */
    public function buildRequest(
        #[\SensitiveParameter] string $message,
        CandidateSet $candidates,
        CarbonImmutable $today,
        ?string $promptReference = null,
        array $retryCodes = [],
    ): AiRequest {
        $prompt = $this->prompts->load($promptReference ?? (string) config('ai.extraction.prompt'));
        $input = $this->input($message, $candidates, $today);
        $payload = $retryCodes === [] ? $input : $input + ['previous_reply_rejected' => array_slice($retryCodes, 0, 10)];

        return new AiRequest(self::PURPOSE_EXTRACTION, $prompt['version'], $prompt['text'], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function input(string $message, CandidateSet $candidates, CarbonImmutable $today): array
    {
        return [
            'today' => $today->format('Y-m-d'),
            'timezone' => $today->getTimezone()->getName(),
            'message' => $message,
        ] + $candidates->toPrompt();
    }
}
