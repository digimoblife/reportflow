<?php

namespace App\Services\Worklog;

use App\Enums\InboundMessageStatus;
use App\Jobs\ProcessInboundMessage;
use App\Models\InboundMessage;
use Illuminate\Support\Facades\DB;

/**
 * Runs an already finished inbound message through the pipeline again (PRD §10, §58; M2 TODO for edited messages).
 * Whatever the earlier run wrote is undone first (activity soft-deleted, state restored, `corrections(undo)`),
 * so reprocessing never duplicates notes. The raw message is never lost: only its status and outcome are reset.
 */
class ReprocessService
{
    private const ALLOWED = [InboundMessageStatus::Processed, InboundMessageStatus::Failed, InboundMessageStatus::NeedsClarification];

    public function __construct(private readonly UndoService $undo) {}

    /**
     * @return array{question_message_ids: list<int>}|null null when the message is not in a finishable state
     *                                                     (already queued or being processed)
     */
    public function reprocess(InboundMessage $message): ?array
    {
        $result = DB::transaction(function () use ($message): ?array {
            $locked = InboundMessage::query()->lockForUpdate()->findOrFail($message->id);

            if (! in_array($locked->status, self::ALLOWED, true)) {
                return null;
            }

            $outcome = Outcome::fromArray($locked->outcome);
            $questions = [];

            if ($outcome !== null) {
                $this->undo->undo($locked);

                foreach ($outcome->items as $item) {
                    if ($item->questionMessageId !== null) {
                        $questions[] = $item->questionMessageId;
                    }
                }
            }

            $locked->forceFill([
                'status' => InboundMessageStatus::Received,
                'error' => null,
                'outcome' => null,
                'reprocess_count' => $locked->reprocess_count + 1,
            ])->save();

            return ['question_message_ids' => $questions];
        });

        if ($result !== null) {
            ProcessInboundMessage::dispatch($message->id, $message->user_id);
        }

        return $result;
    }
}
