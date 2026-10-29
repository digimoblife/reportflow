<?php

use App\Filament\Resources\ScrumEntries\Pages\ListScrumEntries;
use App\Models\Activity;
use App\Models\Person;
use App\Models\Project;
use App\Models\ScrumEntry;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 29, 10, 0, 0, 'Asia/Jakarta'));
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta']);
    actingAsUser($this->user);
    $this->project = asUser($this->user->id, fn () => Project::factory()->create(['name' => 'Harbor Portal']));
    $this->person = asUser($this->user->id, fn () => Person::factory()->create([
        'name' => 'Tiko',
        'project_id' => $this->project->id,
        'role_title' => 'UI/UX Designer',
    ]));
    app()->setLocale('id');
});

afterEach(fn () => Carbon::setTestNow());

describe('scrum entry list', function () {
    it('shows only the signed-in user\'s scrum entries', function () {
        $myEntry = asUser($this->user->id, fn () => ScrumEntry::factory()->create([
            'project_id' => $this->project->id,
            'person_id' => $this->person->id,
            'scrum_date' => '2026-10-29',
            'what_have_done' => 'Desain homepage selesai',
        ]));

        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $otherProject = asUser($other->id, fn () => Project::factory()->create(['name' => 'Other App']));
        $otherPerson = asUser($other->id, fn () => Person::factory()->create(['name' => 'Other Person', 'project_id' => $otherProject->id]));
        $otherEntry = asUser($other->id, fn () => ScrumEntry::factory()->create([
            'project_id' => $otherProject->id,
            'person_id' => $otherPerson->id,
            'scrum_date' => '2026-10-29',
            'what_have_done' => 'Secret project tasks',
        ]));

        Livewire::test(ListScrumEntries::class)
            ->assertCanSeeTableRecords([$myEntry])
            ->assertCanNotSeeTableRecords([$otherEntry]);
    });

    it('can filter by project and person', function () {
        $project2 = asUser($this->user->id, fn () => Project::factory()->create(['name' => 'Kedai Kopi']));
        $person2 = asUser($this->user->id, fn () => Person::factory()->create([
            'name' => 'Budi',
            'project_id' => $project2->id,
        ]));

        $entry1 = asUser($this->user->id, fn () => ScrumEntry::factory()->create([
            'project_id' => $this->project->id,
            'person_id' => $this->person->id,
            'scrum_date' => '2026-10-29',
        ]));

        $entry2 = asUser($this->user->id, fn () => ScrumEntry::factory()->create([
            'project_id' => $project2->id,
            'person_id' => $person2->id,
            'scrum_date' => '2026-10-29',
        ]));

        Livewire::test(ListScrumEntries::class)
            ->filterTable('project_id', $this->project->id)
            ->assertCanSeeTableRecords([$entry1])
            ->assertCanNotSeeTableRecords([$entry2])
            ->removeTableFilter('project_id')
            ->filterTable('person_id', $person2->id)
            ->assertCanSeeTableRecords([$entry2])
            ->assertCanNotSeeTableRecords([$entry1]);
    });

    it('can create a scrum entry and syncs tasks & activities via ScrumService', function () {
        Livewire::test(ListScrumEntries::class)
            ->callAction('create', [
                'scrum_date' => '2026-10-29',
                'project_id' => $this->project->id,
                'person_id' => $this->person->id,
                'what_have_done' => "Desain homepage v1\nReview PR navbar",
                'issue' => 'Menunggu aset ikon dari klien',
                'what_to_do_next' => 'Implementasi halaman checkout',
            ])
            ->assertHasNoActionErrors();

        $entry = asUser($this->user->id, fn () => ScrumEntry::query()->where('person_id', $this->person->id)->first());

        expect($entry)->not->toBeNull()
            ->and($entry->project_id)->toBe($this->project->id)
            ->and($entry->what_have_done)->toBe("Desain homepage v1\nReview PR navbar")
            ->and($entry->issue)->toBe('Menunggu aset ikon dari klien')
            ->and($entry->what_to_do_next)->toBe('Implementasi halaman checkout');

        // Verify task and activity sync (Single Pipeline Rule 2)
        $tasks = asUser($this->user->id, fn () => Task::query()->where('project_id', $this->project->id)->get());
        expect($tasks->count())->toBeGreaterThanOrEqual(3);

        $activities = asUser($this->user->id, fn () => Activity::query()->where('project_id', $this->project->id)->get());
        expect($activities->count())->toBeGreaterThanOrEqual(3);
    });

    it('can edit a scrum entry', function () {
        $entry = asUser($this->user->id, fn () => ScrumEntry::factory()->create([
            'project_id' => $this->project->id,
            'person_id' => $this->person->id,
            'scrum_date' => '2026-10-29',
            'what_have_done' => 'Drafting',
        ]));

        Livewire::test(ListScrumEntries::class)
            ->callTableAction('edit', $entry, [
                'project_id' => $this->project->id,
                'person_id' => $this->person->id,
                'scrum_date' => '2026-10-29',
                'what_have_done' => 'Drafting and finishing',
                'issue' => 'None',
                'what_to_do_next' => 'Next sprint planning',
            ])
            ->assertHasNoTableActionErrors();

        $entry->refresh();
        expect($entry->what_have_done)->toBe('Drafting and finishing')
            ->and($entry->issue)->toBe('None')
            ->and($entry->what_to_do_next)->toBe('Next sprint planning');
    });

    it('can delete a scrum entry', function () {
        $entry = asUser($this->user->id, fn () => ScrumEntry::factory()->create([
            'project_id' => $this->project->id,
            'person_id' => $this->person->id,
            'scrum_date' => '2026-10-29',
        ]));

        Livewire::test(ListScrumEntries::class)
            ->callTableAction('delete', $entry)
            ->assertHasNoTableActionErrors();

        expect(ScrumEntry::query()->whereKey($entry->id)->exists())->toBeFalse();
    });
});
