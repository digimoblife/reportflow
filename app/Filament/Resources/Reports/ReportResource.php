<?php

namespace App\Filament\Resources\Reports;

use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Reports (PRD §22 page 4). Rows are user-scoped through the project; reports are made by the generator, never created
 * or deleted by hand here.
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return (string) __('ui.dashboard.reports.nav');
    }

    public static function getModelLabel(): string
    {
        return (string) __('ui.dashboard.reports.title');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('ui.dashboard.reports.title');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRecordTitle(?Model $record): string
    {
        return $record instanceof Report ? (string) ($record->currentVersion->content['title'] ?? $record->project->name) : '';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'currentVersion']))
            ->defaultSort('updated_at', 'desc')
            ->poll('5s')
            ->columns([
                TextColumn::make('project.name')->label(__('ui.dashboard.reports.columns.project'))->searchable(),
                TextColumn::make('period_start')->label(__('ui.dashboard.reports.columns.period'))
                    ->formatStateUsing(fn ($state, Report $record): string => $record->period_start->format('d M Y').' – '.$record->period_end->format('d M Y').' ('.__('ui.dashboard.reports.types.'.$record->type->value).')'),
                TextColumn::make('language')->label(__('ui.dashboard.reports.columns.language'))->formatStateUsing(fn ($state): string => strtoupper($state->value)),
                TextColumn::make('status')->label(__('ui.dashboard.reports.columns.status'))->badge()
                    ->formatStateUsing(fn (ReportStatus $state): string => (string) __('ui.dashboard.reports.statuses.'.$state->value))
                    ->color(fn (ReportStatus $state): string => match ($state) {
                        ReportStatus::Approved => 'success', ReportStatus::Generating => 'info', ReportStatus::InReview => 'warning', ReportStatus::Cancelled => 'gray', default => 'gray',
                    }),
                TextColumn::make('currentVersion.version_no')->label(__('ui.dashboard.reports.columns.version'))->prefix('v')->placeholder('-'),
                TextColumn::make('updated_at')->label(__('ui.dashboard.reports.columns.updated'))->sortable()
                    ->formatStateUsing(fn ($state): string => $state->copy()->setTimezone(self::timezone())->format('d M Y H:i')),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('ui.dashboard.reports.filters.status'))->multiple()
                    ->options(collect(ReportStatus::cases())->mapWithKeys(fn (ReportStatus $s): array => [$s->value => (string) __('ui.dashboard.reports.statuses.'.$s->value)])->all()),
                SelectFilter::make('project_id')->label(__('ui.dashboard.reports.filters.project'))->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->recordUrl(fn (Report $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
            'view' => ViewReport::route('/{record}'),
        ];
    }

    public static function timezone(): string
    {
        $user = auth()->user();

        return $user instanceof User ? $user->timezone : 'UTC';
    }
}
