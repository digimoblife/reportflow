<?php

namespace App\Services\Worklog;

use App\Enums\OutcomeState;
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
 * candidates -> AI extraction -> backend validation -> ProposalApplier (M4): accepted items are written to
 * tasks/activities in one transaction, unsure ones wait for the user's answer (see Outcome).
 */
class WorklogService
{
    public function __construct(
        private readonly CandidateBuilder $candidates,
        private readonly AIService $ai,
        private readonly ExtractionValidator $validator,
        private readonly ProposalApplier $applier,
        private readonly ReplyCorrectionService $corrections,
    ) {}

    public function process(InboundMessage $message): WorklogResult
    {
        if ($message->correction_of_id !== null && ($corrected = $this->corrections->process($message)) !== null) {
            Log::info('worklog.corrected', [
                'inbound_message_id' => $message->id,
                'corrects' => $message->correction_of_id,
                'items' => $corrected->proposal->summary(),
                'applied' => $corrected->outcome->count(OutcomeState::Applied),
            ]);

            return $corrected;
        }

        $user = User::query()->findOrFail($message->user_id);
        $today = CarbonImmutable::now($user->timezone)->startOfDay();

        // inbound_messages.text is post-redaction by construction (PRD §48).
        $set = $this->candidates->build($message->text, $today);
        $extraction = $this->ai->extractWorklog($message->text, $set, $today, $message->id);
        $proposal = $this->validator->validate($extraction->data, $set, $today);

        $outcome = $this->applier->apply($message, $proposal, $set);

        Log::info('worklog.extracted', [
            'inbound_message_id' => $message->id,
            'prompt' => $extraction->promptVersion,
            'attempts' => $extraction->attempts,
            'items' => $proposal->summary(),
            'applied' => $outcome->count(OutcomeState::Applied),
            'pending' => $outcome->count(OutcomeState::Pending),
        ]);

        return new WorklogResult(proposal: $proposal, outcome: $outcome);
    }
}
