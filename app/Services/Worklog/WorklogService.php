<?php

namespace App\Services\Worklog;

use App\Models\InboundMessage;
use App\Models\User;
use App\Services\Ai\AIService;
use App\Services\Worklog\Extraction\ExtractionValidator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * The single processing path for inbound messages from every channel (CLAUDE.md rule 2, PRD §23).
 * Knows nothing about Telegram or the dashboard; confirmations are delivered by the caller.
 *
 * M3: candidates -> AI extraction -> backend validation -> a ValidatedProposal. Nothing is written to
 * tasks/activities yet, so the user still receives the placeholder confirmation; M4 applies the proposal
 * (it can be rebuilt deterministically from the ai_interactions output of this run).
 */
class WorklogService
{
    public function __construct(
        private readonly CandidateBuilder $candidates,
        private readonly AIService $ai,
        private readonly ExtractionValidator $validator,
    ) {}

    public function process(InboundMessage $message): WorklogResult
    {
        $user = User::query()->findOrFail($message->user_id);
        $today = CarbonImmutable::now($user->timezone)->startOfDay();

        // inbound_messages.text is post-redaction by construction (PRD §48).
        $set = $this->candidates->build($message->text, $today);
        $outcome = $this->ai->extractWorklog($message->text, $set, $today, $message->id);
        $proposal = $this->validator->validate($outcome->data, $set, $today);

        Log::info('worklog.extracted', [
            'inbound_message_id' => $message->id,
            'prompt' => $outcome->promptVersion,
            'attempts' => $outcome->attempts,
            'items' => $proposal->summary(),
        ]);

        return new WorklogResult(proposal: $proposal);
    }
}
