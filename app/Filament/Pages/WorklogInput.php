<?php

namespace App\Filament\Pages;

use App\Domain\Tasks\InvalidTaskStatusTransition;
use App\Enums\OutcomeState;
use App\Enums\TaskStatus;
use App\Jobs\SyncTelegramBubbles;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Worklog\CorrectionService;
use App\Services\Worklog\DashboardSubmission;
use App\Services\Worklog\Outcome;
use App\Services\Worklog\OutcomeItemPresenter;
use App\Services\Worklog\StaleTaskException;
use App\Services\Worklog\SubmissionResult;
use App\Services\Worklog\UndoService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

/**
 * Dashboard home (PRD §22 page 1): a natural-language box that feeds the same pipeline as Telegram, and the latest
 * notes of both channels with their per-item correction buttons. Polls every few seconds, so a note typed in
 * Telegram shows up here without a reload (PRD §23, §74).
 */
class WorklogInput extends Page
{
    protected static string $routePath = '/';

    public static function getRoutePath(Panel $panel): string
    {
        return static::$routePath;
    }

    protected static ?int $navigationSort = -2;

    protected string $view = 'filament.pages.worklog-input';

    public string $text = '';

    public string $submissionKey = '';

    public ?string $notice = null;

    public string $noticeKind = 'info';

    /** Open correction picker: move | status | project */
    public ?string $panelKind = null;

    public ?int $panelMessage = null;

    public ?int $panelItem = null;

    public string $choice = '';

    public static function getNavigationLabel(): string
    {
        return (string) __('ui.dashboard.worklog.nav');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedPencilSquare;
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('ui.dashboard.worklog.title');
    }

    public function mount(): void
    {
        $this->submissionKey = (string) Str::uuid();
    }

    public function submit(DashboardSubmission $submission): void
    {
        $result = $submission->submit($this->text, $this->submissionKey);

        if ($result->accepted()) {
            $this->text = '';
            $this->submissionKey = (string) Str::uuid();   // the next note is a new submission
        }

        $this->notice = match (true) {
            $result->status === SubmissionResult::QUEUED && $result->secretCount > 0 => __('ui.dashboard.worklog.notices.queued').' '.__('ui.dashboard.worklog.notices.secrets', ['count' => $result->secretCount]),
            default => __('ui.dashboard.worklog.notices.'.$result->status),
        };
        $this->noticeKind = $result->accepted() ? 'success' : 'warning';
    }

    public function undo(int $messageId, ?int $index = null): void
    {
        $message = InboundMessage::query()->findOrFail($messageId);
        $undone = app(UndoService::class)->undo($message, $index === null ? null : [$index]);

        $partial = array_filter($undone, fn (array $u): bool => $u['partial']) !== [];
        if ($undone !== []) {
            SyncTelegramBubbles::dispatchFor($message, SyncTelegramBubbles::REFRESH);
        }

        $this->notice = (string) __($undone === [] ? 'ui.dashboard.worklog.notices.not_possible' : ($partial ? 'ui.dashboard.worklog.notices.undone_partial' : 'ui.dashboard.worklog.notices.undone'));
        $this->noticeKind = $undone === [] ? 'warning' : 'success';
        $this->closePanel();
    }

    public function openPanel(string $kind, int $messageId, int $index): void
    {
        abort_unless(in_array($kind, ['move', 'status', 'project'], true), 404);
        InboundMessage::query()->findOrFail($messageId);

        $this->panelKind = $kind;
        $this->panelMessage = $messageId;
        $this->panelItem = $index;
        $this->choice = '';
    }

    public function closePanel(): void
    {
        $this->panelKind = $this->panelMessage = $this->panelItem = null;
        $this->choice = '';
    }

    public function applyPanel(): void
    {
        if ($this->panelKind === null || $this->panelMessage === null || $this->panelItem === null || $this->choice === '') {
            return;
        }

        $message = InboundMessage::query()->findOrFail($this->panelMessage);
        $corrections = app(CorrectionService::class);

        try {
            $result = match ($this->panelKind) {
                'move' => $corrections->moveTask($message, $this->panelItem, $this->choice === 'new' ? null : (int) $this->choice),
                'status' => ($to = TaskStatus::tryFrom($this->choice)) === null ? null : $corrections->changeStatus($message, $this->panelItem, $to),
                'project' => $corrections->changeProject($message, $this->panelItem, (int) $this->choice),
                default => null,
            };
        } catch (InvalidTaskStatusTransition) {
            $result = null;
        } catch (StaleTaskException) {
            $this->notice = (string) __('ui.dashboard.worklog.notices.stale');
            $this->noticeKind = 'warning';

            return;
        }

        if ($result !== null) {
            SyncTelegramBubbles::dispatchFor($message, SyncTelegramBubbles::REFRESH);
        }

        $this->notice = (string) __($result === null ? 'ui.dashboard.worklog.notices.not_possible' : 'ui.dashboard.worklog.notices.done');
        $this->noticeKind = $result === null ? 'warning' : 'success';
        $this->closePanel();
    }

    /**
     * Choices for the open picker: [value => label].
     *
     * @return array<string, string>
     */
    #[Computed]
    public function choices(): array
    {
        if ($this->panelKind === null || $this->panelMessage === null || $this->panelItem === null) {
            return [];
        }

        $message = InboundMessage::query()->find($this->panelMessage);
        $item = $message === null ? null : Outcome::fromArray($message->outcome)?->item($this->panelItem);

        if ($item === null) {
            return [];
        }

        $lang = $this->language();

        return match ($this->panelKind) {
            'move' => ['new' => (string) __('ui.dashboard.worklog.pick.new_task')] + Task::query()
                ->whereIn('status', [TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Waiting, TaskStatus::Blocked])
                ->where('id', '!=', $item->taskId ?? 0)->orderByDesc('last_activity_at')->limit(30)->pluck('title', 'id')
                ->mapWithKeys(fn ($title, $id): array => [(string) $id => (string) $title])->all(),
            'status' => ($task = $item->taskId === null ? null : Task::query()->find($item->taskId)) === null ? [] : collect(app(CorrectionService::class)->statusOptions($task))
                ->mapWithKeys(fn (TaskStatus $s): array => [$s->value => (string) __('ui.statuses.'.$s->value, [], $lang)])->all(),
            'project' => Project::query()->where('status', 'active')->where('id', '!=', $item->projectId ?? 0)->orderBy('name')->pluck('name', 'id')
                ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])->all(),
            default => [],
        };
    }

    #[Computed]
    public function panelTaskTitle(): string
    {
        $message = $this->panelMessage === null ? null : InboundMessage::query()->find($this->panelMessage);
        $item = $message === null || $this->panelItem === null ? null : Outcome::fromArray($message->outcome)?->item($this->panelItem);

        return $item?->taskId === null ? '' : (string) Task::query()->whereKey($item->taskId)->value('title');
    }

    /**
     * The latest notes of both channels, ready to render.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function entries(): array
    {
        $user = auth()->user();
        $timezone = $user instanceof User ? $user->timezone : 'UTC';
        $presenter = app(OutcomeItemPresenter::class);

        return InboundMessage::query()->orderByDesc('id')->limit(15)->get()->map(function (InboundMessage $message) use ($presenter, $timezone): array {
            $outcome = Outcome::fromArray($message->outcome);
            $items = [];

            foreach ($outcome->items ?? [] as $item) {
                $items[] = [
                    'index' => $item->index,
                    'state' => $item->state->value,
                    'applied' => $item->state === OutcomeState::Applied,
                    'view' => $item->state === OutcomeState::Applied ? $presenter->present($item, $timezone) : null,
                    'can_change_project' => $item->createdTask,
                ];
            }

            return [
                'id' => $message->id,
                'source' => $message->source->value,
                'status' => $message->status->value,
                'text' => $message->text,
                'at' => $message->received_at->copy()->setTimezone($timezone)->format('d M Y H:i'),
                'items' => $items,
                'applied_count' => count(array_filter($items, fn (array $i): bool => $i['applied'])),
            ];
        })->values()->all();
    }

    private function language(): string
    {
        $user = auth()->user();

        return $user instanceof User ? $user->default_language->value : 'id';
    }
}
