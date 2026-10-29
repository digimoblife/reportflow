<?php

use App\Enums\ActivityType;
use App\Enums\TaskPersonRole;
use App\Models\Activity;
use App\Models\Person;
use App\Models\Project;
use App\Models\ScrumEntry;
use App\Models\Task;
use App\Services\Telegram\ScrumCallback;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['queue.default' => 'database']);
    Carbon::setTestNow(Carbon::create(2026, 10, 29, 10, 0, 0, 'Asia/Jakarta'));
    $this->tg = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta']);
    actingAsUser($this->tg);
    $this->project = asUser($this->tg->id, fn () => Project::factory()->create(['name' => 'Harbor Portal', 'status' => 'active']));
    $this->tiko = asUser($this->tg->id, fn () => Person::factory()->create([
        'name' => 'Tiko',
        'project_id' => $this->project->id,
        'role_title' => 'UI/UX Designer',
    ]));
    $this->budi = asUser($this->tg->id, fn () => Person::factory()->create([
        'name' => 'Budi',
        'project_id' => $this->project->id,
        'role_title' => 'Backend Developer',
    ]));
});

afterEach(fn () => Carbon::setTestNow());

describe('scrum interactive flow', function () {
    it('starts scrum and displays team members for a single active project', function () {
        send('/scrum', 10);

        $sent = collect(fakeTelegram()->sent)->last();
        expect($sent['text'])->toContain('Sesi Scrum — Harbor Portal')
            ->and($sent['text'])->toContain('Pilih anggota tim')
            ->and(json_encode($sent['keyboard']))->toContain('Tiko')
            ->and(json_encode($sent['keyboard']))->toContain('Budi');
    });

    it('handles interactive member input step by step', function () {
        // 1. Select Tiko
        $memberCallback = (new ScrumCallback('mem', (string) $this->tiko->id))->encode();
        postTelegram(callbackPayload($memberCallback, id: 'cb-1'))->assertOk();

        $menu = collect(fakeTelegram()->sent)->last();
        expect($menu['text'])->toContain('Mencatat Scrum untuk: *Tiko* (Harbor Portal)');

        // 2. Click "What Have Done"
        $doneFieldCallback = (new ScrumCallback('fld', 'done'))->encode();
        postTelegram(callbackPayload($doneFieldCallback, id: 'cb-2'))->assertOk();

        $prompt = collect(fakeTelegram()->sent)->last();
        expect($prompt['text'])->toContain('Ketik apa saja yang sudah dikerjakan/selesai oleh *Tiko*');

        // 3. User types done text
        send('- membuat desain homepage', 11);

        $updatedMenu = collect(fakeTelegram()->sent)->last();
        expect($updatedMenu['text'])->toContain('membuat desain homepage');

        // 4. Click "What To Do Next"
        $nextFieldCallback = (new ScrumCallback('fld', 'next'))->encode();
        postTelegram(callbackPayload($nextFieldCallback, id: 'cb-3'))->assertOk();

        // 5. User types next text
        send('- menyelesaikan desain homepage', 12);

        // 6. Click Save
        $saveCallback = (new ScrumCallback('save'))->encode();
        postTelegram(callbackPayload($saveCallback, id: 'cb-4'))->assertOk();

        $entry = asUser($this->tg->id, fn () => ScrumEntry::query()->where('person_id', $this->tiko->id)->first());

        expect($entry)->not->toBeNull()
            ->and($entry->what_have_done)->toContain('membuat desain homepage')
            ->and($entry->what_to_do_next)->toContain('menyelesaikan desain homepage');

        // Verify task and activity was created with Assignee
        $task = asUser($this->tg->id, fn () => Task::query()->where('title', 'membuat desain homepage')->first());
        expect($task)->not->toBeNull()
            ->and($task->people->first()->id)->toBe($this->tiko->id)
            ->and($task->people->first()->assignment->role)->toBe(TaskPersonRole::Assignee);

        $activity = asUser($this->tg->id, fn () => Activity::query()->where('task_id', $task->id)->first());
        expect($activity)->not->toBeNull()
            ->and($activity->activity_type)->toBe(ActivityType::Development);

        // Bot offers the next remaining member (Budi)
        $nextOffer = collect(fakeTelegram()->sent)->last();
        expect($nextOffer['text'])->toContain('✅ Catatan scrum *Tiko* berhasil disimpan!')
            ->and($nextOffer['text'])->toContain('Lanjut catat anggota berikutnya?')
            ->and(json_encode($nextOffer['keyboard']))->toContain('Budi');
    });

    it('handles direct paste of scrum standup format', function () {
        $text = <<<'TEXT'
Tiko
What Have Done
- membuat mockup landing page

Issue
- font belum tersedia

What To Do Next
- revisi desain banner
TEXT;

        send($text, 20);

        $confirmation = collect(fakeTelegram()->sent)->last();
        expect($confirmation['text'])->toContain('Berhasil mencatat Daily Scrum untuk: *Tiko*');

        $entry = asUser($this->tg->id, fn () => ScrumEntry::query()->where('person_id', $this->tiko->id)->first());
        expect($entry)->not->toBeNull()
            ->and($entry->what_have_done)->toContain('membuat mockup landing page')
            ->and($entry->issue)->toContain('font belum tersedia')
            ->and($entry->what_to_do_next)->toContain('revisi desain banner');
    });
});
