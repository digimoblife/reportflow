<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Enums\OutcomeState;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Task;
use App\Services\Worklog\Outcome;
use App\Services\Worklog\OutcomeItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;

/**
 * Builds the confirmation message, its correction buttons and the clarification questions (PRD §13, §21, §59, §67–§69).
 *
 * The persona sentences come from BotMessages (random variants); everything factual (project, task, status
 * change, activity, date) is structured, plain and always visible, and Completed/Cancelled are spelled out
 * loudly (PRD §14). The text is plain (no parse_mode), so user-derived strings need no escaping.
 */
class ConfirmationComposer
{
    public function __construct(private readonly BotMessages $messages) {}

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>|null}
     */
    public function confirmation(InboundMessage $message, Outcome $outcome, Language $language, string $timezone): array
    {
        if ($outcome->splitRequired) {
            return ['text' => $this->messages->get('worklog.split_required', $language), 'keyboard' => []];
        }

        $applied = $outcome->applied();
        $rejected = $outcome->count(OutcomeState::Rejected);
        $pending = $outcome->count(OutcomeState::Pending);

        if ($applied === []) {
            $lines = [$this->messages->get($pending > 0 ? 'worklog.pending_notice' : 'worklog.nothing_recorded', $language, ['count' => $pending])];
        } else {
            $lines = [$this->messages->get('worklog.recorded', $language)];
            $numbered = count($applied) > 1;

            foreach ($applied as $i => $item) {
                $lines[] = '';
                $lines[] = $this->block($item, $language, $timezone, $numbered ? $i + 1 : null);
            }

            if ($pending > 0) {
                $lines[] = '';
                $lines[] = $this->messages->get('worklog.pending_notice', $language, ['count' => $pending]);
            }
        }

        if ($rejected > 0) {
            $lines[] = '';
            $lines[] = $this->messages->get('worklog.rejected_notice', $language, ['count' => $rejected]);
        }

        return ['text' => implode("\n", $lines), 'keyboard' => $this->correctionKeyboard($message, $applied, $language)];
    }

    /**
     * One item as a block of structured lines (also used after a pending question is answered).
     */
    public function block(OutcomeItem $item, Language $language, string $timezone, ?int $number = null): string
    {
        $lang = $language->value;
        $task = Task::query()->find($item->taskId);
        $project = Project::query()->find($item->projectId);
        $activity = $item->activityId === null ? null : Activity::query()->find($item->activityId);
        $t = fn (string $key): string => (string) Lang::get('ui.'.$key, [], $lang);

        $prefix = $number === null ? '' : "{$number}) ";
        $new = $item->createdTask ? ' ('.$t('labels.new').')' : '';
        $lines = [$prefix.'📁 '.($project === null ? '-' : $project->name).' → '.($task === null ? '-' : $task->title).$new];

        if ($activity !== null) {
            $lines[] = '🛠 '.$t('labels.activity').': '.$t('activity_types.'.$activity->activity_type->value).' — '.mb_substr($activity->summary, 0, 200);
        }

        if ($item->statusTo !== null) {
            $from = $item->statusFrom === null ? '' : $t('statuses.'.$item->statusFrom).' → ';
            $reopened = $item->reopened ? ' ('.$t('labels.reopened').')' : '';
            $lines[] = '🔄 '.$t('labels.status').': '.$from.$t('statuses.'.$item->statusTo).$reopened;
        } elseif ($task !== null) {
            $lines[] = '🔄 '.$t('labels.status').': '.$t('statuses.'.$task->status->value);
        }

        if ($activity !== null && $activity->activity_date->format('Y-m-d') !== CarbonImmutable::now($timezone)->format('Y-m-d')) {
            $lines[] = '📅 '.$this->date($activity->activity_date->format('Y-m-d'), $lang);
        }

        return implode("\n", $lines);
    }

    /**
     * Correction buttons for the applied items (PRD §21): one row of four per item, plus "undo all" when there are several.
     *
     * @param  list<OutcomeItem>  $applied
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public function correctionKeyboard(InboundMessage $message, array $applied, Language $language): array
    {
        $lang = $language->value;
        $label = fn (string $key): string => (string) Lang::get('ui.buttons.'.$key, [], $lang);
        $button = fn (string $text, string $action, ?int $item = null): array => Keyboard::button($text, new CallbackData($message->id, $action, $item));

        if ($applied === []) {
            return [];
        }

        if (count($applied) === 1) {
            $i = $applied[0]->index;

            return [
                [$button($label('undo'), 'undo', $i), $button($label('move'), 'move', $i)],
                [$button($label('status'), 'status', $i), $button($label('project'), 'project', $i)],
            ];
        }

        $rows = [];

        foreach ($applied as $n => $item) {
            $num = $n + 1;
            $rows[] = [
                $button("{$num} ↩️", 'undo', $item->index), $button("{$num} 🔀", 'move', $item->index),
                $button("{$num} ✏️", 'status', $item->index), $button("{$num} 📁", 'project', $item->index),
            ];
        }

        $rows[] = [$button($label('undo_all'), 'undo')];

        return $rows;
    }

    /**
     * The clarification question for a pending item, with its answer buttons (PRD §13, §59, §69).
     *
     * @param  list<Project>  $projects  active projects, offered for a "project" question
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function question(InboundMessage $message, OutcomeItem $item, Language $language, array $projects = []): array
    {
        $lang = $language->value;
        $label = fn (string $key, array $replace = []): string => (string) Lang::get('ui.buttons.'.$key, $replace, $lang);
        $button = fn (string $text, string $action, ?string $arg = null): array => Keyboard::button($text, new CallbackData($message->id, $action, $item->index, $arg));
        $summary = (string) (((array) ($item->pending['activity'] ?? []))['summary'] ?? '');
        $cancel = [$button($label('cancel'), 'skip')];

        return match ($item->question) {
            'project' => [
                'text' => $this->messages->get('question.project', $language)."\n📝 ".mb_substr($summary, 0, 200),
                'keyboard' => [...Keyboard::rows(array_map(fn (Project $p): array => $button(mb_substr($p->name, 0, 30), 'proj', (string) $p->id), array_slice($projects, 0, 8)), 2), $cancel],
            ],
            'date' => [
                'text' => $this->messages->get('question.date', $language, ['date' => $this->date((string) $item->pendingDate, $lang)])."\n📝 ".mb_substr($summary, 0, 200),
                'keyboard' => [[$button($label('keep_date', ['date' => $this->date((string) $item->pendingDate, $lang)]), 'date', 'keep'), $button($label('today'), 'date', 'today')], $cancel],
            ],
            default => $this->matchQuestion($message, $item, $language, $summary, $button, $label, $cancel),
        };
    }

    /**
     * @param  callable(string, string, ?string=): array{text: string, callback_data: string}  $button
     * @param  callable(string, array<string, string>=): string  $label
     * @param  list<array{text: string, callback_data: string}>  $cancel
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function matchQuestion(InboundMessage $message, OutcomeItem $item, Language $language, string $summary, callable $button, callable $label, array $cancel): array
    {
        $proposed = Task::query()->find($item->taskId);
        $rows = [[$button($label('yes'), 'yes'), $button($label('new_task'), 'new')]];

        foreach (Task::query()->whereIn('id', $item->options)->get() as $other) {
            $rows[] = [$button('→ '.mb_substr($other->title, 0, 40), 'pick', (string) $other->id)];
        }

        $rows[] = $cancel;

        return [
            'text' => $this->messages->get('question.match', $language, ['task' => $proposed === null ? '-' : $proposed->title])."\n📝 ".mb_substr($summary, 0, 200),
            'keyboard' => $rows,
        ];
    }

    private function date(string $date, string $lang): string
    {
        /** @var CarbonImmutable $parsed */
        $parsed = CarbonImmutable::parse($date)->locale($lang);

        return $parsed->translatedFormat('j M Y');
    }
}
