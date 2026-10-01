<?php

namespace App\Services\Worklog;

use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AIService;
use App\Services\Worklog\Extraction\ExtractionValidator;
use App\Services\Worklog\Extraction\ItemDecision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Natural-language correction by replying to a confirmation (M4f, PRD §21). The reply is an inbound message of its own
 * (raw input preserved) that points at the message it corrects. The AI proposes the corrected result for the ORIGINAL
 * note; the backend validates it exactly like an extraction (candidate list, schema, confidence), and only then, in one
 * transaction, undoes the old result and applies the new one. If nothing usable comes out, nothing changes.
 */
class ReplyCorrectionService
{
    public function __construct(
        private readonly CandidateBuilder $candidates,
        private readonly AIService $ai,
        private readonly ExtractionValidator $validator,
        private readonly ProposalApplier $applier,
        private readonly UndoService $undo,
    ) {}

    /**
     * @return WorklogResult|null null when there is nothing to correct (the original has no applied items any more)
     */
    public function process(InboundMessage $reply): ?WorklogResult
    {
        $original = $reply->correction_of_id === null ? null : InboundMessage::query()->find($reply->correction_of_id);
        $outcome = $original === null ? null : Outcome::fromArray($original->outcome);
        $applied = $outcome?->applied() ?? [];

        if ($original === null || $applied === []) {
            return null;
        }

        $user = User::query()->findOrFail($reply->user_id);
        $today = CarbonImmutable::now($user->timezone)->startOfDay();

        // Tasks the original created disappear with the undo, so they must not be offered as link targets.
        $createdTaskIds = array_values(array_filter(array_map(fn (OutcomeItem $i): ?int => $i->createdTask ? $i->taskId : null, $applied)));
        $set = $this->candidates->build($original->text."\n".$reply->text, $today)->without($createdTaskIds);

        $extraction = $this->ai->correctWorklog($original->text, $reply->text, $this->previous($applied), $set, $today, $reply->id);
        $proposal = $this->validator->validate($extraction->data, $set, $today);

        $usable = array_filter($proposal->items, fn ($item): bool => $item->decision !== ItemDecision::Rejected) !== [];

        return DB::transaction(function () use ($original, $reply, $proposal, $set, $usable): WorklogResult {
            // Only replace the old result when the correction produced something that can be written or asked.
            if ($usable) {
                $this->undo->undo($original);
            }

            return new WorklogResult(proposal: $proposal, outcome: $this->applier->apply($reply, $proposal, $set));
        });
    }

    /**
     * @param  list<OutcomeItem>  $applied
     * @return list<array<string, mixed>>
     */
    private function previous(array $applied): array
    {
        return array_map(function (OutcomeItem $item): array {
            $task = $item->taskId === null ? null : Task::query()->find($item->taskId);
            $project = $item->projectId === null ? null : Project::query()->find($item->projectId);
            $activity = (array) ($item->pending['activity'] ?? []);

            return [
                'project' => $project?->name,
                'project_id' => $item->projectId,
                'task' => $task?->title,
                'task_id' => $item->createdTask ? null : $item->taskId,
                'new_task' => $item->createdTask,
                'activity' => ['type' => $activity['type'] ?? null, 'summary' => $activity['summary'] ?? null, 'date' => $activity['date'] ?? null],
                'status_change' => $item->statusTo === null ? null : ['from' => $item->statusFrom, 'to' => $item->statusTo],
            ];
        }, $applied);
    }
}
