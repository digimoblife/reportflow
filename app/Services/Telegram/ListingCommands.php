<?php

namespace App\Services\Telegram;

use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Lang;

/**
 * Read-only views behind /projects, /project, /tasks, /task and /inbox (PRD §20). Every query is user-scoped by the
 * models; texts are labels and data (the persona lives only in the one-line headings from lang bot.list.*).
 * Each view returns the message text and an inline keyboard (paging, picking, reprocess).
 */
class ListingCommands
{
    public const PAGE_SIZE = 12;

    private const ACTIVE = [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Waiting, TaskStatus::Blocked];

    private const TEXT_LIMIT = 3800; // Telegram caps a message at 4096 characters

    public function __construct(private readonly BotMessages $messages) {}

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function projects(Language $language): array
    {
        $projects = Project::query()->where('status', ProjectStatus::Active)->orderBy('name')->get();

        if ($projects->isEmpty()) {
            return $this->plain($this->messages->get('list.projects_empty', $language));
        }

        $counts = $this->activeCounts(array_values($projects->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $lang = $language->value;
        $lines = [$this->messages->get('list.projects_title', $language, ['count' => $projects->count()]), ''];

        foreach ($projects as $project) {
            $lines[] = '📁 '.$project->name.' — '.($counts[$project->id] ?? 0).' '.$this->label('active_tasks', $lang);
        }

        return $this->plain($this->fit($lines));
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function project(?string $argument, Language $language, string $timezone): array
    {
        if ($argument === null) {
            return $this->projects($language);
        }

        $matches = $this->findProjects($argument);

        if ($matches === []) {
            return $this->plain($this->messages->get('list.project_not_found', $language));
        }

        if (count($matches) > 1) {
            $rows = array_map(fn (Project $p): array => [['text' => '📁 '.mb_substr($p->name, 0, 40), 'callback_data' => (new ViewData('project', 0, $p->id))->encode()]], array_slice($matches, 0, 8));

            return ['text' => $this->messages->get('list.project_pick', $language), 'keyboard' => $rows];
        }

        return $this->projectDetail($matches[0], $language, $timezone);
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function projectById(int $id, Language $language, string $timezone): array
    {
        $project = Project::query()->find($id);

        return $project === null
            ? $this->plain($this->messages->get('list.project_not_found', $language))
            : $this->projectDetail($project, $language, $timezone);
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function tasks(int $page, Language $language, string $timezone): array
    {
        $total = Task::query()->whereIn('status', self::ACTIVE)->count();

        if ($total === 0) {
            return $this->plain($this->messages->get('list.tasks_empty', $language));
        }

        $pages = (int) ceil($total / self::PAGE_SIZE);
        $page = max(0, min($page, $pages - 1));
        $tasks = Task::query()->with('project')->whereIn('status', self::ACTIVE)
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->offset($page * self::PAGE_SIZE)->limit(self::PAGE_SIZE)->get();

        $lang = $language->value;
        $lines = [$this->messages->get('list.tasks_title', $language, ['count' => $total, 'page' => $page + 1, 'pages' => $pages])];
        $current = null;

        foreach ($tasks->sortBy(fn (Task $t): string => ($t->project->name ?? '')) as $task) {
            if ($current !== $task->project_id) {
                $current = $task->project_id;
                $lines[] = '';
                $lines[] = '📁 '.($task->project->name ?? '');
            }

            $lines[] = '#'.$task->id.' '.$this->clip($task->title, 60).' — '.$this->status($task, $lang);
        }

        $nav = [];

        if ($page > 0) {
            $nav[] = ['text' => (string) Lang::get('ui.buttons.prev', [], $lang), 'callback_data' => (new ViewData('tasks', $page - 1))->encode()];
        }

        if ($page < $pages - 1) {
            $nav[] = ['text' => (string) Lang::get('ui.buttons.next', [], $lang), 'callback_data' => (new ViewData('tasks', $page + 1))->encode()];
        }

        return ['text' => $this->fit($lines), 'keyboard' => $nav === [] ? [] : [$nav]];
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function task(?string $argument, Language $language, string $timezone): array
    {
        if ($argument === null) {
            return $this->plain($this->messages->get('list.task_usage', $language));
        }

        $matches = $this->findTasks($argument);

        if ($matches === []) {
            return $this->plain($this->messages->get('list.task_not_found', $language));
        }

        if (count($matches) > 1) {
            $rows = array_map(fn (Task $t): array => [['text' => '#'.$t->id.' '.$this->clip($t->title, 40), 'callback_data' => (new ViewData('task', 0, $t->id))->encode()]], array_slice($matches, 0, 8));

            return ['text' => $this->messages->get('list.task_pick', $language), 'keyboard' => $rows];
        }

        return $this->taskDetail($matches[0], $language, $timezone);
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function taskById(int $id, Language $language, string $timezone): array
    {
        $task = Task::query()->with('project')->find($id);

        return $task === null
            ? $this->plain($this->messages->get('list.task_not_found', $language))
            : $this->taskDetail($task, $language, $timezone);
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public function inbox(Language $language, string $timezone): array
    {
        $messages = InboundMessage::query()
            ->whereIn('status', [InboundMessageStatus::Failed, InboundMessageStatus::NeedsClarification])
            ->orderByDesc('id')->limit(8)->get();

        if ($messages->isEmpty()) {
            return $this->plain($this->messages->get('list.inbox_empty', $language));
        }

        $lang = $language->value;
        $lines = [$this->messages->get('list.inbox_title', $language, ['count' => $messages->count()]), ''];
        $rows = [];

        foreach ($messages->values() as $i => $message) {
            $n = $i + 1;
            $reason = $message->status === InboundMessageStatus::Failed ? $this->label('inbox_failed', $lang) : $this->label('inbox_pending', $lang);
            $lines[] = "{$n}. ".$this->date($message->received_at, $timezone, $lang).' — '.$reason;
            $lines[] = '   '.$this->clip(str_replace("\n", ' ', $message->text), 70);
            $rows[] = [Keyboard::button("{$n}. ".(string) Lang::get('ui.buttons.redo', [], $lang), new CallbackData($message->id, 'redo'))];
        }

        return ['text' => $this->fit($lines), 'keyboard' => $rows];
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function projectDetail(Project $project, Language $language, string $timezone): array
    {
        $lang = $language->value;
        $tasks = Task::query()->where('project_id', $project->id)->whereIn('status', self::ACTIVE)
            ->orderByDesc('last_activity_at')->orderByDesc('id')->get();
        $completed = Task::query()->where('project_id', $project->id)->where('status', TaskStatus::Completed)->count();

        $lines = [
            '📁 '.$project->name,
            $tasks->count().' '.$this->label('active_tasks', $lang).' · '.$completed.' '.$this->label('completed_tasks', $lang),
            '',
        ];

        foreach ($tasks->take(self::PAGE_SIZE) as $task) {
            $lines[] = '#'.$task->id.' '.$this->clip($task->title, 60).' — '.$this->status($task, $lang);
        }

        if ($tasks->count() > self::PAGE_SIZE) {
            $lines[] = $this->label('more', $lang, ['count' => $tasks->count() - self::PAGE_SIZE]);
        }

        return $this->plain($this->fit($lines));
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function taskDetail(Task $task, Language $language, string $timezone): array
    {
        $lang = $language->value;
        $task->loadMissing('project');

        $lines = [
            '#'.$task->id.' '.$task->title,
            '📁 '.($task->project->name ?? ''),
            '🔄 '.$this->status($task, $lang),
        ];

        if ($task->last_activity_at !== null) {
            $lines[] = '🕒 '.$this->label('last_activity', $lang).': '.$this->date($task->last_activity_at, $timezone, $lang);
        }

        $events = TaskEvent::query()->where('task_id', $task->id)->orderByDesc('id')->limit(8)->get()->reverse();
        $lines[] = '';
        $lines[] = $this->label('timeline', $lang).':';

        foreach ($events as $event) {
            $lines[] = '• '.$this->date($event->created_at, $timezone, $lang).' — '.$this->eventLabel($event, $lang);
        }

        $activities = Activity::query()->where('task_id', $task->id)->orderByDesc('activity_date')->orderByDesc('id')->limit(3)->get();
        $lines[] = '';
        $lines[] = $this->label('recent', $lang).':';

        if ($activities->isEmpty()) {
            $lines[] = '• '.$this->label('none', $lang);
        }

        foreach ($activities as $activity) {
            $lines[] = '• '.$this->date($activity->activity_date, $timezone, $lang).' — '.(string) Lang::get('ui.activity_types.'.$activity->activity_type->value, [], $lang).': '.$this->clip($activity->summary, 120);
        }

        return $this->plain($this->fit($lines));
    }

    private function eventLabel(TaskEvent $event, string $lang): string
    {
        $label = (string) Lang::get('ui.events.'.$event->event_type->value, [], $lang);
        $from = $event->from_value['status'] ?? null;
        $to = $event->to_value['status'] ?? null;

        if (is_string($to) && TaskStatus::tryFrom($to) !== null) {
            $to = (string) Lang::get('ui.statuses.'.$to, [], $lang);
            $from = is_string($from) && TaskStatus::tryFrom($from) !== null ? (string) Lang::get('ui.statuses.'.$from, [], $lang) : null;

            return $label.': '.($from !== null ? $from.' → ' : '').$to;
        }

        return $label;
    }

    /**
     * @return list<Project>
     */
    private function findProjects(string $term): array
    {
        $term = mb_strtolower($term);
        $all = Project::query()->where('status', ProjectStatus::Active)->orderBy('name')->get();

        $exact = $all->filter(fn (Project $p): bool => mb_strtolower($p->name) === $term || $p->slug === $term
            || in_array($term, array_map(fn ($a): string => mb_strtolower((string) $a), $p->aliases ?? []), true));

        if ($exact->isNotEmpty()) {
            return array_values($exact->all());
        }

        return array_values($all->filter(fn (Project $p): bool => str_contains(mb_strtolower($p->name), $term)
            || array_filter($p->aliases ?? [], fn ($a): bool => str_contains(mb_strtolower((string) $a), $term)) !== [])->all());
    }

    /**
     * @return list<Task>
     */
    private function findTasks(string $term): array
    {
        $term = trim($term, " #\t");

        if (ctype_digit($term) && strlen($term) <= 12) {
            $task = Task::query()->with('project')->find((int) $term);

            return $task === null ? [] : [$task];
        }

        $escaped = addcslashes($term, '%_\\');

        return array_values(Task::query()->with('project')->where('title', 'ilike', '%'.$escaped.'%')
            ->orderByRaw("case when status in ('open','in_progress','waiting','blocked') then 0 else 1 end")
            ->orderByDesc('last_activity_at')->orderByDesc('id')->limit(9)->get()->all());
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, int>
     */
    private function activeCounts(array $projectIds): array
    {
        /** @var array<int, int> $counts */
        $counts = Task::query()->whereIn('project_id', $projectIds)->whereIn('status', self::ACTIVE)
            ->selectRaw('project_id, count(*) as total')->groupBy('project_id')->pluck('total', 'project_id')->map(fn ($n): int => (int) $n)->all();

        return $counts;
    }

    private function status(Task $task, string $lang): string
    {
        $label = (string) Lang::get('ui.statuses.'.$task->status->value, [], $lang);

        if ($task->status === TaskStatus::Waiting && $task->waiting_reason !== null) {
            $label .= ' ('.(string) Lang::get('ui.lists.waiting_for', [], $lang).' '.(string) Lang::get('ui.waiting_reasons.'.$task->waiting_reason->value, [], $lang).')';
        }

        return $label;
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    private function label(string $key, string $lang, array $replace = []): string
    {
        return (string) Lang::get('ui.lists.'.$key, $replace, $lang);
    }

    private function date(CarbonInterface $date, string $timezone, string $lang): string
    {
        return $date->copy()->setTimezone($timezone)->locale($lang)->translatedFormat('j M Y');
    }

    private function clip(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /**
     * @param  list<string>  $lines
     */
    private function fit(array $lines): string
    {
        $text = implode("\n", $lines);

        return mb_strlen($text) > self::TEXT_LIMIT ? mb_substr($text, 0, self::TEXT_LIMIT - 1).'…' : $text;
    }

    /**
     * @return array{text: string, keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    private function plain(string $text): array
    {
        return ['text' => $text, 'keyboard' => []];
    }
}
