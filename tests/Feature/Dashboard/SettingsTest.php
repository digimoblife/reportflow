<?php

use App\Enums\Language;
use App\Enums\ProjectStatus;
use App\Filament\Pages\Settings;
use App\Models\Project;
use App\Models\User;
use App\Services\Reminder\ReminderSettings;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta', 'default_language' => Language::Indonesian, 'reminders_enabled' => false]);
    $this->w = worklogWorld($this->user);
    $this->actingAs($this->user);
    app()->setLocale('id');
});

describe('account settings', function () {
    it('shows the current values and saves new ones', function () {
        Livewire::test(Settings::class)
            ->assertSet('language', 'id')->assertSet('timezone', 'Asia/Jakarta')
            ->set('language', 'en')->set('timezone', 'Asia/Makassar')->set('workdays', ['mon', 'wed'])->set('remindersEnabled', true)->set('reminderTime', '17:45')->set('monthlyTime', '08:15')->set('monthlyDaysBefore', 5)->set('monthlyEnabled', false)
            ->call('saveProfile')->assertSee('Settings saved');

        $user = $this->user->fresh();
        expect($user->default_language)->toBe(Language::English)->and($user->timezone)->toBe('Asia/Makassar')
            ->and($user->workdays)->toBe(['mon', 'wed'])->and($user->reminders_enabled)->toBeTrue()
            ->and(app(ReminderSettings::class)->time())->toBe('17:45')
            ->and(app(ReminderSettings::class)->monthlyTime())->toBe('08:15')->and(app(ReminderSettings::class)->monthlyDaysBefore())->toBe(5)->and(app(ReminderSettings::class)->monthly()->enabled)->toBeFalse();
    });

    it('rejects an unknown language, timezone or weekday without saving anything', function (array $changes) {
        $page = Livewire::test(Settings::class);
        foreach ($changes as $property => $value) {
            $page->set($property, $value);
        }
        $page->call('saveProfile')->assertSee('tidak valid');

        expect($this->user->fresh()->timezone)->toBe('Asia/Jakarta')->and($this->user->fresh()->default_language)->toBe(Language::Indonesian);
    })->with([
        'language' => [['language' => 'jv']],
        'timezone' => [['timezone' => 'Mars/Olympus']],
        'weekday' => [['workdays' => ['funday']]],
        'no workdays' => [['workdays' => []]],
        'reminder time' => [['reminderTime' => '25:00']],
        'monthly time' => [['monthlyTime' => 'soon']],
        'monthly days' => [['monthlyDaysBefore' => 9]],
    ]);
});

describe('projects', function () {
    it('adds, renames, gives aliases to, archives and reactivates a project', function () {
        $page = Livewire::test(Settings::class)->set('newProject', '  Toko   Kita ')->call('addProject')->assertSee('Project ditambahkan');
        $project = Project::query()->where('slug', 'toko-kita')->firstOrFail();
        expect($project->name)->toBe('Toko Kita');

        $page->set("names.{$project->id}", 'Toko Kita Online')->set("aliases.{$project->id}", 'TK, tk, Toko ,  ')->call('saveProject', $project->id);
        expect($project->fresh()->name)->toBe('Toko Kita Online')->and($project->fresh()->slug)->toBe('toko-kita-online')
            ->and($project->fresh()->aliases)->toBe(['TK', 'Toko']);

        $page->call('setProjectActive', $project->id, false);
        expect($project->fresh()->status)->toBe(ProjectStatus::Archived);
        $page->call('setProjectActive', $project->id, true);
        expect($project->fresh()->status)->toBe(ProjectStatus::Active);
    });

    it('refuses a name that another project already uses, or an empty one', function () {
        $page = Livewire::test(Settings::class)->set("names.{$this->w['kedai']->id}", 'Harbor Portal')->call('saveProject', $this->w['kedai']->id)->assertSee('sudah dipakai');
        expect($this->w['kedai']->fresh()->name)->toBe('Kedai App');

        $page->set('newProject', '   ')->call('addProject')->assertSee('tidak valid');
    });

    it('cannot touch another user\'s project', function () {
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        $theirs = asUser($other->id, fn () => Project::factory()->create(['user_id' => $other->id, 'name' => 'Not yours', 'slug' => 'not-yours']));

        Livewire::test(Settings::class)->assertDontSee('Not yours')->call('setProjectActive', $theirs->id, false)->assertNotFound();

        expect(asUser($other->id, fn () => $theirs->fresh()->status))->toBe(ProjectStatus::Active);
    });
});

it('is reachable from the panel', function () {
    $this->get('/admin/settings')->assertOk()->assertSee('Pengaturan');
});

it('shows the monthly reminder offset, three days before the end by default', function () {
    Livewire::test(Settings::class)->assertSet('monthlyDaysBefore', 3)->assertSee('3 hari sebelum akhir bulan');
});
