<?php

namespace App\Services\Worklog;

use App\Models\InboundMessage;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiRequest;

/**
 * The single processing path for inbound messages from every channel (CLAUDE.md rule 2, PRD §23).
 * Knows nothing about Telegram or the dashboard; confirmations are delivered by the caller.
 *
 * M2 skeleton: calls the (fake) AI provider with the already-redacted text and returns a placeholder.
 * M3 adds extraction/matching, M4 validation and writes. Recording to ai_interactions arrives with
 * AIService in M3.
 */
class WorklogService
{
    public function __construct(private readonly AiProvider $ai) {}

    public function process(InboundMessage $message): WorklogResult
    {
        // inbound_messages.text is post-redaction by construction (PRD §48).
        $this->ai->complete(new AiRequest('worklog.skeleton', 'm2-skeleton', $message->text));

        return new WorklogResult;
    }
}
