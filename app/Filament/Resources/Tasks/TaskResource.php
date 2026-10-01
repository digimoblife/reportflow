<?php

namespace App\Filament\Resources\Tasks;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tasks page (PRD §22 page 2). Rows are user-scoped by the Task model; there is no create or delete here: tasks
 * come from notes, and every change goes through TaskLifecycle (matrix, version, task_events).
 */
class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = -1;

    public static function getNavigationLabel(): string
    {
        return (string) __('ui.dashboard.tasks.nav');
    }

    public static function getModelLabel(): string
    {
        return (string) __('ui.dashboard.tasks.title');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('ui.dashboard.tasks.title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return (string) __('ui.dashboard.tasks.title');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        return $record instanceof Task ? $record->title : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project'))
            ->defaultSort('last_activity_at', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('id')->label(__('ui.dashboard.tasks.columns.id'))->sortable(),
                TextColumn::make('title')->label(__('ui.dashboard.tasks.columns.title'))->searchable()->wrap(),
                TextColumn::make('project.name')->label(__('ui.dashboard.tasks.columns.project'))->sortable(),
                TextColumn::make('status')->label(__('ui.dashboard.tasks.columns.status'))->badge()
                    ->formatStateUsing(fn (TaskStatus $state): string => (string) __('ui.statuses.'.$state->value))
                    ->color(fn (TaskStatus $state): string => match ($state) {
                        TaskStatus::Completed => 'success', TaskStatus::InProgress => 'info', TaskStatus::Waiting => 'warning',
                        TaskStatus::Blocked => 'danger', default => 'gray',
                    }),
                TextColumn::make('last_activity_at')->label(__('ui.dashboard.tasks.columns.last_activity'))->sortable()
                    ->formatStateUsing(fn ($state): string => $state === null ? '-' : CarbonImmutable::parse($state)->setTimezone(self::timezone())->format('d M Y')),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('ui.dashboard.tasks.filters.status'))->multiple()
                    ->options(collect(TaskStatus::cases())->mapWithKeys(fn (TaskStatus $s): array => [$s->value => (string) __('ui.statuses.'.$s->value)])->all()),
                SelectFilter::make('project_id')->label(__('ui.dashboard.tasks.filters.project'))
                    ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('period')->label(__('ui.dashboard.tasks.filters.period'))
                    ->schema([
                        DatePicker::make('from')->label(__('ui.dashboard.tasks.filters.from')),
                        DatePicker::make('until')->label(__('ui.dashboard.tasks.filters.until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        // Dates are the user's calendar days; the column is UTC.
                        $tz = self::timezone();

                        return $query
                            ->when(filled($data['from'] ?? null), fn (Builder $q) => $q->where('last_activity_at', '>=', CarbonImmutable::parse((string) $data['from'], $tz)->startOfDay()->utc()))
                            ->when(filled($data['until'] ?? null), fn (Builder $q) => $q->where('last_activity_at', '<=', CarbonImmutable::parse((string) $data['until'], $tz)->endOfDay()->utc()));
                    }),
            ])
            ->recordActions([ViewAction::make()])
            ->recordUrl(fn (Task $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'view' => ViewTask::route('/{record}'),
        ];
    }

    public static function timezone(): string
    {
        $user = auth()->user();

        return $user instanceof User ? $user->timezone : 'UTC';
    }
}
