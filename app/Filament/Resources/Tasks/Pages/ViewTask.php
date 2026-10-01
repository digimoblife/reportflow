<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Domain\Tasks\InvalidTaskStatusTransition;
use App\Domain\Tasks\TaskStatusTransition;
use App\Enums\EventActor;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Services\Worklog\ActivityMover;
use App\Services\Worklog\StaleTaskException;
use App\Services\Worklog\TaskLifecycle;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

/**
 * Task detail (PRD §22): details, the timeline of task_events and activities, and the three edits. Edits are
 * guarded by the version the page was loaded with: if the task changed meanwhile (for example from Telegram), the edit
 * is refused with a clear message instead of overwriting (PRD §23, optimistic locking).
 *
 * @extends ViewRecord<Task>
 */
class ViewTask extends ViewRecord
{
    protected static string $resource = TaskResource::class;

    protected string $view = 'filament.resources.tasks.view-task';

    /** The task version the person is looking at. */
    #[Locked]
    public int $loadedVersion = 0;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->loadedVersion = $this->task()->version;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->task()->title;
    }

    public function task(): Task
    {
        /** @var Task $task */
        $task = $this->getRecord();

        return $task;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rename')
                ->label(__('ui.dashboard.tasks.actions.rename'))
                ->color('gray')
                ->schema([TextInput::make('title')->label(__('ui.dashboard.tasks.form.title'))->required()->maxLength(200)])
                ->fillForm(fn (): array => ['title' => $this->task()->title])
                ->action(fn (array $data) => $this->edit(function (Task $task) use ($data): string {
                    $changed = app(TaskLifecycle::class)->rename($task, (string) $data['title'], EventActor::User, null, $this->loadedVersion);

                    return $changed ? 'renamed' : 'unchanged';
                })),

            Action::make('status')
                ->label(__('ui.dashboard.tasks.actions.status'))
                ->color('gray')
                ->schema([
                    Select::make('status')->label(__('ui.dashboard.tasks.form.status'))->required()
                        ->options(fn (): array => collect(TaskStatusTransition::allowedFrom($this->task()->status))
                            ->mapWithKeys(fn (TaskStatus $s): array => [$s->value => (string) __('ui.statuses.'.$s->value)])->all()),
                ])
                ->action(fn (array $data) => $this->edit(function (Task $task) use ($data): string {
                    $to = TaskStatus::tryFrom((string) $data['status']);

                    if ($to === null) {
                        return 'status_invalid';
                    }

                    try {
                        app(TaskLifecycle::class)->changeStatus($task, $to, EventActor::User, null, $this->loadedVersion);
                    } catch (InvalidTaskStatusTransition) {
                        return 'status_invalid';
                    }

                    return 'status_changed';
                })),

            Action::make('move')
                ->label(__('ui.dashboard.tasks.actions.move'))
                ->color('gray')
                ->schema([
                    CheckboxList::make('activities')->label(__('ui.dashboard.tasks.form.activities'))->required()
                        ->options(fn (): array => Activity::query()->where('task_id', $this->task()->id)->orderByDesc('activity_date')->orderByDesc('id')->limit(50)->get()
                            ->mapWithKeys(fn (Activity $a): array => [$a->id => $a->activity_date->format('d M Y').' — '.mb_substr($a->summary, 0, 80)])->all()),
                    Select::make('target')->label(__('ui.dashboard.tasks.form.target'))->required()->searchable()
                        ->options(fn (): array => Task::query()->where('id', '!=', $this->task()->id)->orderByDesc('last_activity_at')->limit(100)->pluck('title', 'id')->all()),
                ])
                ->action(function (array $data): void {
                    $target = Task::query()->find((int) $data['target']);

                    if ($target === null) {
                        return;
                    }

                    try {
                        $moved = app(ActivityMover::class)->move(
                            $this->task(),
                            $target,
                            array_values(array_map('intval', (array) $data['activities'])),
                            TaskResource::timezone(),
                            $this->loadedVersion,
                            $target->version,
                        );
                    } catch (StaleTaskException) {
                        $this->notify('stale', false);

                        return;
                    }

                    $this->refreshRecord();
                    $this->notify($moved > 0 ? 'moved' : 'move_none', $moved > 0, ['count' => $moved]);
                }),

            Action::make('reload')
                ->label(__('ui.dashboard.tasks.actions.reload'))
                ->color('gray')
                ->outlined()
                ->action(function (): void {
                    $this->refreshRecord();
                    $this->notify('reloaded', true);
                }),
        ];
    }

    /**
     * @param  callable(Task): string  $change  returns the notice key; may throw StaleTaskException
     */
    private function edit(callable $change): void
    {
        try {
            $key = $change($this->task());
        } catch (StaleTaskException) {
            $this->notify('stale', false);

            return;
        }

        $this->refreshRecord();
        $this->notify($key, in_array($key, ['renamed', 'status_changed'], true));
    }

    private function refreshRecord(): void
    {
        $this->record = Task::query()->with('project')->findOrFail($this->task()->id);
        $this->loadedVersion = $this->task()->version;
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    private function notify(string $key, bool $success, array $replace = []): void
    {
        $notification = Notification::make()->title((string) __('ui.dashboard.tasks.notices.'.$key, $replace));

        ($success ? $notification->success() : $notification->warning())->send();
    }

    /**
     * Newest first: events of this task and its activities, merged for the timeline.
     *
     * @return array{events: Collection<int, TaskEvent>, activities: Collection<int, Activity>}
     */
    public function timeline(): array
    {
        return [
            'events' => TaskEvent::query()->where('task_id', $this->task()->id)->orderByDesc('id')->limit(50)->get(),
            'activities' => Activity::query()->where('task_id', $this->task()->id)->orderByDesc('activity_date')->orderByDesc('id')->limit(50)->get(),
        ];
    }

    public function eventLabel(TaskEvent $event): string
    {
        $status = fn (?string $value): string => $value !== null && TaskStatus::tryFrom($value) !== null ? (string) __('ui.statuses.'.$value) : '-';

        return (string) __('ui.dashboard.tasks.events.'.$event->event_type->value, [
            'from' => $event->event_type->value === 'title_changed' ? (string) ($event->from_value['title'] ?? '') : $status($event->from_value['status'] ?? null),
            'to' => $event->event_type->value === 'title_changed' ? (string) ($event->to_value['title'] ?? '') : $status($event->to_value['status'] ?? null),
        ]);
    }
}
