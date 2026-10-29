<?php

use App\Models\Person;
use App\Models\Project;
use App\Models\ScrumEntry;
use App\Models\User;

describe('scrum:morning-brief (PRD Scrum §5)', function () {
    it('compiles latest What To Do Next into Rencana Hari Ini and sends to Telegram', function () {
        $user = User::factory()->create([
            'telegram_user_id' => 998877,
            'timezone' => 'Asia/Jakarta',
            'workdays' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'reminders_enabled' => true,
        ]);

        actingAsUser($user);

        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name' => 'Project Harbor Portal',
        ]);

        $tiko = Person::factory()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'name' => 'Tiko',
        ]);

        $budi = Person::factory()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'name' => 'Budi',
        ]);

        // Yesterday's standup
        $scrumDate = now('Asia/Jakarta')->subDay()->toDateString();

        ScrumEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'person_id' => $tiko->id,
            'scrum_date' => $scrumDate,
            'what_have_done' => "- membuat desain homepage\n- review PR",
            'issue' => 'komponen website kurang',
            'what_to_do_next' => "- menyelesaikan desain homepage\n- membuat komponen website yang kurang\n- membuat draft wireframe halaman about us",
        ]);

        ScrumEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'person_id' => $budi->id,
            'scrum_date' => $scrumDate,
            'what_have_done' => '- setup auth backend',
            'issue' => null,
            'what_to_do_next' => "- integrasi payment gateway\n- testing endpoint webhook",
        ]);

        $this->artisan("scrum:morning-brief --user={$user->id}")->assertSuccessful();

        $messages = fakeTelegram()->sent;
        expect($messages)->not->toBeEmpty();

        $sent = end($messages);
        expect($sent['chat_id'])->toBe(998877)
            ->and($sent['text'])->toContain('Morning Scrum Brief — Project Harbor Portal')
            ->and($sent['text'])->toContain('👤 Tiko')
            ->and($sent['text'])->toContain('• Rencana Hari Ini:')
            ->and($sent['text'])->toContain('- Menyelesaikan desain homepage')
            ->and($sent['text'])->toContain('• Issue: Komponen website kurang')
            ->and($sent['text'])->not->toContain('membuat desain homepage') // what have done is NOT included
            ->and($sent['text'])->not->toContain('What Have Done')
            ->and($sent['text'])->toContain('👤 Budi')
            ->and($sent['text'])->toContain('- Integrasi payment gateway')
            ->and($sent['text'])->toContain('(Bahan pegangan obrolan Daily Standup pagi ini)');
    });

    it('handles /scrum brief on-demand via Telegram', function () {
        $user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta']);
        actingAsUser($user);

        $project = Project::factory()->create([
            'user_id' => $user->id,
            'name' => 'Project Alpha',
        ]);

        $andi = Person::factory()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'name' => 'Andi',
        ]);

        ScrumEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'person_id' => $andi->id,
            'scrum_date' => now()->toDateString(),
            'what_have_done' => '- riset AI model',
            'issue' => null,
            'what_to_do_next' => '- implementasi prompt template',
        ]);

        send('/scrum brief', 10);

        $messages = fakeTelegram()->sent;
        $sent = end($messages);
        expect($sent['chat_id'])->toBe(555001)
            ->and($sent['text'])->toContain('Morning Scrum Brief — Project Alpha')
            ->and($sent['text'])->toContain('👤 Andi')
            ->and($sent['text'])->toContain('• Rencana Hari Ini:')
            ->and($sent['text'])->toContain('- Implementasi prompt template');
    });

    it('respects workdays and 08:30 time check when running via scheduler without force', function () {
        $user = User::factory()->create([
            'telegram_user_id' => 555666,
            'timezone' => 'Asia/Jakarta',
            'workdays' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'reminders_enabled' => true,
        ]);

        actingAsUser($user);

        $project = Project::factory()->create(['user_id' => $user->id]);
        $person = Person::factory()->create(['user_id' => $user->id, 'project_id' => $project->id]);

        ScrumEntry::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'person_id' => $person->id,
            'scrum_date' => now()->toDateString(),
            'what_to_do_next' => 'testing',
        ]);

        fakeTelegram()->sent = [];

        // 14:00 is not 08:30, so scheduled run without force must send nothing
        $this->artisan('scrum:morning-brief')->assertSuccessful();
        expect(fakeTelegram()->sent)->toBeEmpty();
    });
});
