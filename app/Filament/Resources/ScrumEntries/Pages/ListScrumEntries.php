<?php

namespace App\Filament\Resources\ScrumEntries\Pages;

use App\Filament\Resources\ScrumEntries\ScrumEntryResource;
use App\Models\ScrumEntry;
use App\Models\User;
use App\Services\Scrum\ScrumService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListScrumEntries extends ListRecords
{
    protected static string $resource = ScrumEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label((string) __('ui.dashboard.scrum.actions.create'))
                ->using(function (array $data): ScrumEntry {
                    $user = auth()->user();
                    if ($user instanceof User) {
                        return app(ScrumService::class)->saveEntry(
                            $user,
                            (int) $data['project_id'],
                            (int) $data['person_id'],
                            [
                                'what_have_done' => $data['what_have_done'] ?? null,
                                'issue' => $data['issue'] ?? null,
                                'what_to_do_next' => $data['what_to_do_next'] ?? null,
                            ],
                            $data['scrum_date'] ?? null
                        );
                    }

                    return ScrumEntry::create($data);
                }),
        ];
    }
}
