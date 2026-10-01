<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Enums\Language;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Report\ReportGenerator;
use App\Services\Report\ReportRequests;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [self::generateAction()];
    }

    /**
     * The "Generate" modal: project, period, language, and the pre-generate choice when entries are still being processed
     * (PRD §23). Shared with the report page's "Regenerate".
     */
    public static function generateAction(?int $projectId = null, ?string $from = null, ?string $until = null, ?string $language = null, string $name = 'generate'): Action
    {
        return Action::make($name)
            ->label(__($name === 'generate' ? 'ui.dashboard.reports.generate.action' : 'ui.dashboard.reports.generate.regenerate'))
            ->schema(function () use ($projectId, $from, $until, $language): array {
                $pending = app(ReportGenerator::class)->pendingEntries();
                $user = auth()->user();
                $lastMonth = CarbonImmutable::now($user instanceof User ? $user->timezone : 'UTC')->subMonthNoOverflow();

                return [
                    Select::make('project_id')->label(__('ui.dashboard.reports.generate.project'))->required()->default($projectId)
                        ->options(fn (): array => Project::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
                    DatePicker::make('from')->label(__('ui.dashboard.reports.generate.from'))->required()->default($from ?? $lastMonth->startOfMonth()->format('Y-m-d')),
                    DatePicker::make('until')->label(__('ui.dashboard.reports.generate.until'))->required()->default($until ?? $lastMonth->endOfMonth()->format('Y-m-d')),
                    Select::make('language')->label(__('ui.dashboard.reports.generate.language'))->required()->default($language ?? ($user instanceof User ? $user->default_language->value : 'id'))
                        ->options(['id' => 'Indonesia', 'en' => 'English']),
                    ...($pending > 0 ? [Radio::make('pending_choice')->label(__('ui.dashboard.reports.generate.pending', ['count' => $pending]))->required()
                        ->options(['wait' => (string) __('ui.dashboard.reports.generate.wait'), 'skip' => (string) __('ui.dashboard.reports.generate.skip')])->default('wait')] : []),
                ];
            })
            ->action(function (array $data): void {
                $project = Project::query()->find((int) $data['project_id']);

                if ($project === null) {
                    return;
                }

                $result = app(ReportRequests::class)->request($project, (string) $data['from'], (string) $data['until'], Language::from((string) $data['language']), ($data['pending_choice'] ?? 'skip') === 'wait');

                $note = Notification::make()->title((string) __('ui.dashboard.reports.notices.'.$result['status']));
                ($result['status'] === ReportRequests::QUEUED ? $note->success() : $note->warning())->send();
            });
    }
}
