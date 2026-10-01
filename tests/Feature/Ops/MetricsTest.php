<?php

use App\Enums\CorrectionType;
use App\Enums\InboundMessageStatus;
use App\Enums\ReminderState;
use App\Filament\Pages\Health;
use App\Jobs\ProcessInboundMessage;
use App\Jobs\RenderReportFiles;
use App\Models\AiInteraction;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\ReminderInstance;
use App\Models\ReminderRule;
use App\Models\Report;
use App\Models\ReportVersion;
use App\Models\SystemEvent;
use App\Models\User;
use App\Services\Ai\Fakes\FakeAiProvider;
use App\Services\Ops\MetricsService;
use App\Services\Ops\OpsEvents;
use App\Services\Report\Pdf\PdfRenderException;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramMessenger;
use App\Services\Worklog\WorklogService;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 10, 12, 0, 0, 'UTC'));
    $this->user = User::factory()->create(['telegram_user_id' => 555001, 'timezone' => 'Asia/Jakarta']);
    actingAsUser($this->user);
    $this->project = Project::factory()->create(['name' => 'Harbor Portal']);
    $this->metrics = app(MetricsService::class);
    $this->from = CarbonImmutable::now('UTC')->subDays(7);
    $this->to = CarbonImmutable::now('UTC');
});

afterEach(fn () => Carbon::setTestNow());

function summary(): array
{
    return test()->metrics->summary(test()->from, test()->to);
}

function outcomeWith(array $states): array
{
    return ['items' => array_map(fn ($state, $i) => ['index' => $i, 'state' => $state], $states, array_keys($states))];
}

describe('AI requests and costs', function () {
    it('counts requests and failures, latency percentiles and tokens', function () {
        foreach ([1000, 2000, 3000] as $ms) {
            AiInteraction::factory()->create(['latency_ms' => $ms, 'tokens_input' => 100, 'tokens_output' => 20, 'project_id' => $this->project->id]);
        }
        AiInteraction::factory()->create(['success' => false, 'latency_ms' => 5000, 'tokens_input' => 50, 'tokens_output' => 0, 'purpose' => 'report_section']);

        $ai = summary()['ai'];

        expect($ai)->toMatchArray(['requests' => 4, 'failed' => 1, 'failure_rate' => 0.25, 'p50_ms' => 2500, 'tokens_input' => 350, 'tokens_output' => 60])
            ->and($ai['p95_ms'])->toBeGreaterThan(4000);
        expect(summary()['targets']['ai_failure_rate'])->toMatchArray(['ok' => false]);
    });

    it('breaks usage down by purpose and by project, and estimates a cost only when prices are configured', function () {
        AiInteraction::factory()->create(['purpose' => 'worklog_extraction', 'project_id' => $this->project->id, 'model' => 'deepseek-flash', 'tokens_input' => 1_000_000, 'tokens_output' => 500_000]);
        AiInteraction::factory()->create(['purpose' => 'report_section', 'model' => 'deepseek-flash', 'tokens_input' => 1000, 'tokens_output' => 500]);

        $costs = summary()['costs'];
        expect(array_column($costs['by_purpose'], 'purpose'))->toBe(['report_section', 'worklog_extraction'])
            ->and($costs['by_project'])->toBe([['project' => 'Harbor Portal', 'tokens_input' => 1_000_000, 'tokens_output' => 500_000]])
            ->and($costs['estimate'])->toBeNull();

        config(['ai.pricing' => ['deepseek-flash' => ['input_per_million' => 0.5, 'output_per_million' => 2.0]]]);
        expect(summary()['costs']['estimate'])->toBe(round((1_001_000 * 0.5 + 500_500 * 2.0) / 1_000_000, 4));
    });

    it('ignores the period outside the window and other users', function () {
        AiInteraction::factory()->create(['created_at' => now()->subDays(8)]);
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        asUser($other->id, fn () => AiInteraction::factory()->create());

        expect(summary()['ai']['requests'])->toBe(0)->and(summary()['ai']['failure_rate'])->toBeNull();
    });
});

describe('notes and the correction rate', function () {
    it('measures the time from arrival to processed, first run only', function () {
        foreach ([2, 4, 12] as $seconds) {
            InboundMessage::factory()->create(['received_at' => now()->subHour(), 'processed_at' => now()->subHour()->addSeconds($seconds), 'status' => InboundMessageStatus::Processed]);
        }
        InboundMessage::factory()->create(['received_at' => now()->subHour(), 'processed_at' => now()->subMinutes(10), 'reprocess_count' => 1, 'status' => InboundMessageStatus::Processed]);

        $w = summary()['worklog'];

        expect($w)->toMatchArray(['processed' => 3, 'p50_seconds' => 4.0])->and($w['p95_seconds'])->toBeGreaterThan(10.0);
        expect(summary()['targets']['worklog_p95']['ok'])->toBeFalse();
    });

    it('computes the User Correction Rate from corrections over applied or later-undone interpretations', function () {
        InboundMessage::factory()->create(['outcome' => outcomeWith(['applied', 'applied', 'undone', 'pending', 'rejected', 'skipped'])]);   // 3 count
        InboundMessage::factory()->create(['outcome' => outcomeWith(['applied'])]);   // 1
        InboundMessage::factory()->create(['outcome' => null]);
        Correction::factory()->create(['correction_type' => CorrectionType::Undo]);
        Correction::factory()->create(['correction_type' => CorrectionType::MoveTask]);

        $c = summary()['corrections'];

        expect($c)->toMatchArray(['applied' => 4, 'corrections' => 2, 'rate' => 0.5, 'by_type' => ['undo' => 1, 'move_task' => 1]])
            ->and(summary()['targets']['correction_rate'])->toMatchArray(['ok' => false, 'target' => 0.15]);

        foreach (range(1, 20) as $_) {
            InboundMessage::factory()->create(['outcome' => outcomeWith(['applied'])]);
        }
        expect(summary()['targets']['correction_rate']['ok'])->toBeTrue();   // 2 / 24
    });

    it('does not call "no data" a pass', function () {
        $t = summary()['targets'];

        expect(array_column($t, 'ok'))->each->toBeNull();
    });
});

describe('reports, reminders, queue and Telegram', function () {
    it('measures report generation, failures and PDF failures', function () {
        $report = Report::factory()->create(['project_id' => $this->project->id]);
        foreach ([1000, 3000] as $i => $ms) {
            ReportVersion::factory()->create(['report_id' => $report->id, 'version_no' => $i + 1, 'content' => ['meta' => ['generation_ms' => $ms]]]);
        }
        SystemEvent::factory()->create(['type' => OpsEvents::REPORT_FAILED]);
        SystemEvent::factory()->create(['type' => OpsEvents::PDF_FAILED]);

        $r = summary()['reports'];

        expect($r)->toMatchArray(['generated' => 2, 'failed' => 1, 'avg_ms' => 2000, 'pdf_failed' => 1, 'pdf_success_rate' => 0.5])
            ->and(round($r['success_rate'], 3))->toBe(0.667)->and(summary()['targets']['pdf_success']['ok'])->toBeFalse();
    });

    it('counts reminders sent, acted on, dismissed and snoozed', function () {
        $rule = ReminderRule::factory()->create();
        ReminderInstance::factory()->create(['reminder_rule_id' => $rule->id, 'reminder_date' => '2026-10-05', 'status' => ReminderState::Acknowledged, 'sent_at' => now()->subDay(), 'send_count' => 1]);
        ReminderInstance::factory()->create(['reminder_rule_id' => $rule->id, 'reminder_date' => '2026-10-06', 'status' => ReminderState::Dismissed, 'sent_at' => now()->subDays(2), 'send_count' => 1]);
        ReminderInstance::factory()->create(['reminder_rule_id' => $rule->id, 'reminder_date' => '2026-10-07', 'status' => ReminderState::Snoozed, 'sent_at' => now()->subDays(3), 'snooze_count' => 2]);
        ReminderInstance::factory()->create(['reminder_rule_id' => $rule->id, 'reminder_date' => '2026-10-08', 'status' => ReminderState::Scheduled]);

        expect(summary()['reminders'])->toMatchArray(['sent' => 3, 'acknowledged' => 1, 'dismissed' => 1, 'snoozes' => 2]);
        expect(round(summary()['reminders']['conversion'], 3))->toBe(0.333);
    });

    it('counts failed jobs and Telegram delivery failures', function () {
        DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDay()]);
        DB::table('failed_jobs')->insert(['uuid' => 'u2', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(20)]);
        SystemEvent::factory()->count(2)->create(['type' => OpsEvents::TELEGRAM_FAILED]);

        $s = summary();

        expect($s['queue'])->toMatchArray(['failed_total' => 2, 'failed_period' => 1])->and($s['telegram_failures'])->toBe(2);
    });
});

describe('events are recorded where things go wrong', function () {
    it('records a failed best-effort Telegram send as a code, never the text', function () {
        fakeTelegram()->failNextSend(TelegramApiException::fromResponse('sendMessage', 403, 'Forbidden: bot was blocked'));

        app(TelegramMessenger::class)->trySend(555001, 'secret client message text');

        $event = SystemEvent::query()->sole();
        expect($event->type)->toBe(OpsEvents::TELEGRAM_FAILED)->and($event->context)->toBe(['method' => 'sendMessage', 'status' => 403])->and(json_encode($event->context))->not->toContain('secret');
    });

    it('records a final PDF failure, and a report generation crash', function () {
        (new RenderReportFiles(1, $this->user->id))->failed(PdfRenderException::unreachable());

        expect(SystemEvent::query()->where('type', OpsEvents::PDF_FAILED)->sole()->context)->toBe(['code' => 'pdf_engine_unreachable']);
    });

    it('stamps the time a message finished processing', function () {
        $message = InboundMessage::factory()->create(['text' => 'Harbor: sesuatu', 'status' => InboundMessageStatus::Received]);
        fakeAi()->using(fn () => FakeAiProvider::EMPTY_EXTRACTION);

        (new ProcessInboundMessage($message->id, $this->user->id))->handle(app(WorklogService::class));

        expect($message->fresh()->processed_at)->not->toBeNull()->and($message->fresh()->status)->toBe(InboundMessageStatus::Processed);
    });

    it('records events with the acting user, or none when the system acts', function () {
        OpsEvents::record('probe', ['n' => 1]);
        app(UserContext::class)->runAsSystem(fn () => OpsEvents::record('probe', ['n' => 2]));

        $events = app(UserContext::class)->runAsSystem(fn () => SystemEvent::query()->where('type', 'probe')->orderBy('id')->pluck('user_id')->all());
        expect($events)->toBe([$this->user->id, null]);
    });
});

describe('the Health page', function () {
    it('shows the targets and usage for the signed-in user only', function () {
        $this->actingAs($this->user);
        app()->setLocale('id');
        AiInteraction::factory()->create(['purpose' => 'report_section', 'tokens_input' => 4321]);
        $other = User::factory()->create(['telegram_user_id' => 888001]);
        asUser($other->id, fn () => AiInteraction::factory()->create(['purpose' => 'secret_purpose']));

        Livewire::test(Health::class)->assertSee('Tingkat koreksi pengguna')->assertSee('report_section')->assertSee('4,321')->assertDontSee('secret_purpose')
            ->assertSee('harga token belum diisi')->call('setDays', 30)->assertSet('days', 30);
        $this->get('/admin/health')->assertOk();
    });
});
