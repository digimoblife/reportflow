<?php

namespace App\Filament\Resources\ScrumEntries;

use App\Filament\Resources\ScrumEntries\Pages\ListScrumEntries;
use App\Models\Person;
use App\Models\Project;
use App\Models\ScrumEntry;
use App\Models\User;
use App\Services\Scrum\ScrumService;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * PRD Scrum: Daily standup records per team member and project.
 */
class ScrumEntryResource extends Resource
{
    protected static ?string $model = ScrumEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return (string) __('ui.dashboard.scrum.nav');
    }

    public static function getModelLabel(): string
    {
        return (string) __('ui.dashboard.scrum.title');
    }

    public static function getPluralModelLabel(): string
    {
        return (string) __('ui.dashboard.scrum.plural');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return (string) __('ui.dashboard.scrum.plural');
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if ($record instanceof ScrumEntry) {
            $name = $record->person instanceof Person ? $record->person->name : 'Member';
            $date = $record->scrum_date->format('d M Y');

            return "{$name} — {$date}";
        }

        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('scrum_date')
                ->label(__('ui.dashboard.scrum.fields.date'))
                ->required()
                ->default(fn () => now()->toDateString()),
            Select::make('project_id')
                ->label(__('ui.dashboard.scrum.fields.project'))
                ->options(fn (): array => Project::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                ->required()
                ->searchable()
                ->preload()
                ->live(),
            Select::make('person_id')
                ->label(__('ui.dashboard.scrum.fields.person'))
                ->options(function (callable $get): array {
                    $projectId = $get('project_id');

                    return Person::query()
                        ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all();
                })
                ->required()
                ->searchable()
                ->preload(),
            Textarea::make('what_to_do_next')
                ->label(__('ui.dashboard.scrum.fields.what_to_do_next'))
                ->placeholder((string) __('ui.dashboard.scrum.fields.what_to_do_next_placeholder'))
                ->rows(3),
            Textarea::make('what_have_done')
                ->label(__('ui.dashboard.scrum.fields.what_have_done'))
                ->placeholder((string) __('ui.dashboard.scrum.fields.what_have_done_placeholder'))
                ->rows(3),
            Textarea::make('issue')
                ->label(__('ui.dashboard.scrum.fields.issue'))
                ->placeholder((string) __('ui.dashboard.scrum.fields.issue_placeholder'))
                ->rows(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['project', 'person']))
            ->defaultSort('scrum_date', 'desc')
            ->columns([
                TextColumn::make('scrum_date')
                    ->label(__('ui.dashboard.scrum.columns.date'))
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('person.name')
                    ->label(__('ui.dashboard.scrum.columns.person'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('project.name')
                    ->label(__('ui.dashboard.scrum.columns.project'))
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('what_to_do_next')
                    ->label(__('ui.dashboard.scrum.columns.what_to_do_next'))
                    ->limit(45)
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('what_have_done')
                    ->label(__('ui.dashboard.scrum.columns.what_have_done'))
                    ->limit(45)
                    ->wrap()
                    ->placeholder('-'),
                TextColumn::make('issue')
                    ->label(__('ui.dashboard.scrum.columns.issue'))
                    ->limit(35)
                    ->color(fn ($state) => filled($state) ? 'danger' : null)
                    ->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('project_id')
                    ->label(__('ui.dashboard.scrum.filters.project'))
                    ->options(fn (): array => Project::query()->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('person_id')
                    ->label(__('ui.dashboard.scrum.filters.person'))
                    ->options(fn (): array => Person::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->using(function (ScrumEntry $record, array $data): ScrumEntry {
                        $user = auth()->user();
                        if ($user instanceof User) {
                            $newDate = (string) ($data['scrum_date'] ?? $record->scrum_date->format('Y-m-d'));
                            $newProjectId = (int) $data['project_id'];
                            $newPersonId = (int) $data['person_id'];

                            if ($record->project_id !== $newProjectId ||
                                $record->person_id !== $newPersonId ||
                                $record->scrum_date->format('Y-m-d') !== $newDate) {
                                $record->delete();
                            }

                            return app(ScrumService::class)->saveEntry(
                                $user,
                                $newProjectId,
                                $newPersonId,
                                [
                                    'what_have_done' => $data['what_have_done'] ?? null,
                                    'issue' => $data['issue'] ?? null,
                                    'what_to_do_next' => $data['what_to_do_next'] ?? null,
                                ],
                                $newDate
                            );
                        }

                        $record->update($data);

                        return $record;
                    }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScrumEntries::route('/'),
        ];
    }
}
