<?php

namespace App\Filament\Resources\TeamMembers;

use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Models\Person;
use App\Models\Project;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Team Members management (PRD Scrum: team members assigned to projects).
 */
class TeamMemberResource extends Resource
{
    protected static ?string $model = Person::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 0;

    public static function getNavigationLabel(): string
    {
        return (string) __('ui.dashboard.team.nav');
    }

    public static function getModelLabel(): string
    {
        return (string) __('ui.dashboard.team.title');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('ui.dashboard.team.plural');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return (string) __('ui.dashboard.team.plural');
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        return $record instanceof Person ? $record->name : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('ui.dashboard.team.fields.name'))
                ->required()
                ->maxLength(255),
            Select::make('project_id')
                ->label(__('ui.dashboard.team.fields.project'))
                ->options(fn (): array => Project::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->preload()
                ->nullable(),
            TextInput::make('role_title')
                ->label(__('ui.dashboard.team.fields.role'))
                ->placeholder((string) __('ui.dashboard.team.fields.role_placeholder'))
                ->maxLength(100),
            TagsInput::make('aliases')
                ->label(__('ui.dashboard.team.fields.aliases'))
                ->helperText((string) __('ui.dashboard.team.fields.aliases_helper')),
            Textarea::make('notes')
                ->label(__('ui.dashboard.team.fields.notes'))
                ->rows(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('project')->withCount('tasks'))
            ->defaultSort('name', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label(__('ui.dashboard.team.columns.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('project.name')
                    ->label(__('ui.dashboard.team.columns.project'))
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('role_title')
                    ->label(__('ui.dashboard.team.columns.role'))
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('aliases')
                    ->label(__('ui.dashboard.team.columns.aliases'))
                    ->badge()
                    ->separator(',')
                    ->placeholder('-'),
                TextColumn::make('tasks_count')
                    ->counts('tasks')
                    ->label(__('ui.dashboard.team.columns.tasks_count'))
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('ui.dashboard.team.columns.created_at'))
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('project_id')
                    ->label(__('ui.dashboard.team.filters.project'))
                    ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeamMembers::route('/'),
        ];
    }
}
