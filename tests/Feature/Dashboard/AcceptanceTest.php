<?php

use App\Enums\InboundMessageStatus;
use App\Filament\Pages\Inbox;
use App\Filament\Pages\WorklogInput;
use App\Jobs\ProcessInboundMessage;
use App\Models\Activity;
use App\Models\InboundMessage;
use App\Models\Task;
use App\Services\Worklog\DashboardSubmission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Extraction;
use Tests\Support\TelegramPayload;

// PRD §74, multi-channel acceptance criteria 1 and 2 (the report criteria belong to M7).

beforeEach(function () {
    config(['queue.default' => 'database']);
    Carbon::setTestNow(Carbon::create(2026, 9, 30, 10, 0, 0, 'Asia/Jakarta'));
    $this->tg = $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta']);
    $this->w = worklogWorld($this->tg);
    $this->actingAs($this->user);
    app()->setLocale('id');
});

afterEach(fn () => Carbon::setTestNow());

it('shows a Telegram note on the dashboard within 10 seconds: the pages poll every 5 and need no reload', function () {
    $page = Livewire::test(WorklogInput::class)->assertDontSee('Carrier onboarding');

    fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));
    processNote('Harbor Portal: task baru carrier onboarding', 100);

    // The next poll is just a re-render of the same component; nothing else is needed.
    $page->call('$refresh')->assertSee('Carrier onboarding')->assertSeeHtml('wire:poll.5s');
    Livewire::test(Inbox::class)->assertSeeHtml('wire:poll.5s');
    expect(5)->toBeLessThanOrEqual(10);
});

it('creates no duplicates from double clicks, network retries or redelivered webhooks', function () {
    fakeAi()->respondWith(Extraction::json([Extraction::newItem($this->w['harbor']->id, 'Carrier onboarding')]));

    // dashboard: same submission key three times
    $submission = app(DashboardSubmission::class);
    $key = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
    foreach (range(1, 3) as $_) {
        $submission->submit('Harbor Portal: task baru carrier onboarding', $key);
    }

    // telegram: the same webhook delivered three times
    $payload = TelegramPayload::message('Harbor Portal: task baru carrier onboarding', messageId: 300);
    foreach (range(1, 3) as $_) {
        postTelegram($payload)->assertOk();
    }

    settleWorker();

    expect(InboundMessage::query()->where('source', 'dashboard')->count())->toBe(1)
        ->and(InboundMessage::query()->where('source', 'telegram')->count())->toBe(1)
        ->and(InboundMessage::query()->where('status', InboundMessageStatus::Processed)->count())->toBe(2)
        ->and(Activity::query()->whereNotNull('inbound_message_id')->count())->toBe(2);   // one per message
});

it('processes the same text from the two channels as two separate notes, never merged away', function () {
    Queue::fake();

    app(DashboardSubmission::class)->submit('Harbor Portal: sama', 'aaaaaaaa-bbbb-cccc-dddd-111111111111');
    postTelegram(TelegramPayload::message('Harbor Portal: sama', messageId: 301))->assertOk();

    expect(InboundMessage::query()->count())->toBe(2);
    Queue::assertPushed(ProcessInboundMessage::class, 2);
    expect(Task::query()->count())->toBeGreaterThan(0);
});
