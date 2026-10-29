<?php

use App\Filament\Resources\TeamMembers\Pages\ListTeamMembers;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 29, 10, 0, 0, 'Asia/Jakarta'));
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta']);
    actingAsUser($this->user);
    $this->project = asUser($this->user->id, fn () => Project::factory()->create(['name' => 'Harbor Portal']));
    app()->setLocale('id');
});

afterEach(fn () => Carbon::setTestNow());

describe('team member list', function () {
    it('shows only the signed-in user\'s team members', function () {
        $tiko = asUser($this->user->id, fn () => Person::factory()->create([
            'name' => 'Tiko',
            'project_id' => $this->project->id,
            'role_title' => 'UI/UX Designer',
        ]));

        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $otherPerson = asUser($other->id, fn () => Person::factory()->create([
            'name' => 'Secret Other Person',
            'user_id' => $other->id,
        ]));

        Livewire::test(ListTeamMembers::class)
            ->assertCanSeeTableRecords([$tiko])
            ->assertCanNotSeeTableRecords([$otherPerson]);
    });

    it('can filter by project and search by name', function () {
        $project2 = asUser($this->user->id, fn () => Project::factory()->create(['name' => 'Kedai Kopi']));

        $tiko = asUser($this->user->id, fn () => Person::factory()->create([
            'name' => 'Tiko',
            'project_id' => $this->project->id,
        ]));
        $budi = asUser($this->user->id, fn () => Person::factory()->create([
            'name' => 'Budi',
            'project_id' => $project2->id,
        ]));

        Livewire::test(ListTeamMembers::class)
            ->filterTable('project_id', $this->project->id)
            ->assertCanSeeTableRecords([$tiko])
            ->assertCanNotSeeTableRecords([$budi])
            ->removeTableFilter('project_id')
            ->searchTable('Budi')
            ->assertCanSeeTableRecords([$budi])
            ->assertCanNotSeeTableRecords([$tiko]);
    });

    it('can create a team member with project and role', function () {
        Livewire::test(ListTeamMembers::class)
            ->callAction('create', [
                'name' => 'Andi Pratama',
                'project_id' => $this->project->id,
                'role_title' => 'Frontend Developer',
                'aliases' => ['andi', 'ndik'],
                'notes' => 'Senior Frontend Dev',
            ])
            ->assertHasNoActionErrors();

        $person = asUser($this->user->id, fn () => Person::query()->where('name', 'Andi Pratama')->first());

        expect($person)->not->toBeNull()
            ->and($person->project_id)->toBe($this->project->id)
            ->and($person->role_title)->toBe('Frontend Developer')
            ->and($person->aliases)->toBe(['andi', 'ndik'])
            ->and($person->notes)->toBe('Senior Frontend Dev')
            ->and($person->user_id)->toBe($this->user->id);
    });

    it('can edit a team member', function () {
        $person = asUser($this->user->id, fn () => Person::factory()->create([
            'name' => 'Tiko',
            'project_id' => $this->project->id,
            'role_title' => 'Junior Designer',
        ]));

        Livewire::test(ListTeamMembers::class)
            ->callTableAction('edit', $person, [
                'name' => 'Tiko Updated',
                'role_title' => 'Lead UI/UX Designer',
            ])
            ->assertHasNoTableActionErrors();

        $person->refresh();
        expect($person->name)->toBe('Tiko Updated')
            ->and($person->role_title)->toBe('Lead UI/UX Designer');
    });

    it('can delete a team member', function () {
        $person = asUser($this->user->id, fn () => Person::factory()->create([
            'name' => 'Tiko',
            'project_id' => $this->project->id,
        ]));

        Livewire::test(ListTeamMembers::class)
            ->callTableAction('delete', $person)
            ->assertHasNoTableActionErrors();

        expect(Person::query()->whereKey($person->id)->exists())->toBeFalse();
    });
});
