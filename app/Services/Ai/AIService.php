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

    public const PURPOSE_CORRECTION = 'worklog_correction';

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

        return $this->run(
            self::PURPOSE_EXTRACTION,
            $prompt['version'],
            $input,
            fn (array $retryCodes): AiRequest => $this->buildRequest($message, $candidates, $today, $promptReference, $retryCodes),
            $inboundMessageId,
            $candidates->detectedProjectIds[0] ?? null,
        );
    }

    /**
     * Corrects the result of an earlier note from the person's reply to its confirmation (M4f). Same output schema,
     * same validation and retry rules as extraction; the caller validates the proposal and applies it.
     *
     * @param  string  $original  the REDACTED original note
     * @param  string  $correction  the REDACTED reply
     * @param  list<array<string, mixed>>  $previous  what was recorded from the original note
     *
     * @throws AiProviderException
     * @throws AiExtractionFailed
     * @throws JsonException
     */
    public function correctWorklog(
        #[\SensitiveParameter] string $original,
        #[\SensitiveParameter] string $correction,
        array $previous,
        CandidateSet $candidates,
        CarbonImmutable $today,
        ?int $inboundMessageId = null,
    ): ExtractionOutcome {
        $prompt = $this->prompts->load((string) config('ai.correction.prompt'));
        $input = [
            'today' => $today->format('Y-m-d'),
            'timezone' => $today->getTimezone()->getName(),
            'original_message' => $original,
            'previous_result' => $previous,
            'correction' => $correction,
        ] + $candidates->toPrompt();

        return $this->run(
            self::PURPOSE_CORRECTION,
            $prompt['version'],
            $input,
            function (array $retryCodes) use ($prompt, $input): AiRequest {
                $payload = $retryCodes === [] ? $input : $input + ['previous_reply_rejected' => array_slice($retryCodes, 0, 10)];

                return new AiRequest(self::PURPOSE_CORRECTION, $prompt['version'], $prompt['text'], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            },
            $inboundMessageId,
            $candidates->detectedProjectIds[0] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  callable(list<string>): AiRequest  $makeRequest
     */
    private function run(string $purpose, string $promptVersion, array $input, callable $makeRequest, ?int $inboundMessageId, ?int $projectId): ExtractionOutcome
    {
        $retryCodes = [];
        $tokensIn = 0;
        $tokensOut = 0;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $request = $makeRequest($retryCodes);

            try {
                $response = $this->provider->complete($request);
            } catch (AiProviderException $e) {
                $this->recorder->record($purpose, $this->provider->name(), $promptVersion, $input, null, false, 'provider_error', inboundMessageId: $inboundMessageId, projectId: $projectId);

                throw $e;
            }

            $tokensIn += $response->tokensInput ?? 0;
            $tokensOut += $response->tokensOutput ?? 0;

            $decoded = JsonOutput::decode($response->content);
            $codes = $decoded === null
                ? [trim($response->content) === '' ? 'empty_reply' : 'invalid_json']
                : $this->schema->errors($decoded);

            $this->recorder->record(
                $purpose,
                $response->model,
                $promptVersion,
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
                return new ExtractionOutcome($decoded, $promptVersion, $attempt, $tokensIn, $tokensOut);
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
