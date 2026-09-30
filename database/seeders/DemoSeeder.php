<?php

namespace Database\Seeders;

use App\Domain\Tasks\TaskStatusTransition;
use App\Enums\ActivitySource;
use App\Enums\ActivityType;
use App\Enums\CorrectionType;
use App\Enums\EventActor;
use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\MessageSource;
use App\Enums\ReminderType;
use App\Enums\TaskEventType;
use App\Enums\TaskPersonRole;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WaitingReason;
use App\Models\Activity;
use App\Models\AiInteraction;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Person;
use App\Models\Project;
use App\Models\ReminderRule;
use App\Models\ReportTemplate;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fictional demo data for local development (APP_ENV=local only). No real client names.
 *
 * Task histories are replayed through TaskStatusTransition so the seeded task_events
 * always respect the PRD §14 matrix. Dates are relative to the current month so the
 * data always spans a month boundary (cross-month tasks, PRD §16).
 */
class DemoSeeder extends Seeder
{
    private const DEMO_EMAIL = 'demo@example.test';

    private const DEMO_CHAT_ID = 100000001;

    private int $nextMessageId = 1;

    private User $owner;

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder may only run with APP_ENV=local.');
        }

        $this->owner = $this->resolveOwner();

        app(UserContext::class)->runAs($this->owner->id, function (): void {
            if (Project::exists()) {
                $this->command->info("Demo data already present for [{$this->owner->email}]. Skipping.");

                return;
            }

            $this->seedDemoData();
            $this->command->info("Demo data seeded for [{$this->owner->email}].");
        });
    }

    private function resolveOwner(): User
    {
        $devEmail = config('app.dev_user.email');

        if (is_string($devEmail) && $devEmail !== '') {
            $devUser = User::where('email', $devEmail)->first();

            if ($devUser !== null) {
                return $devUser;
            }
        }

        // No dev login configured: own the demo data by a user nobody can log in as.
        return User::firstOrCreate(
            ['email' => self::DEMO_EMAIL],
            ['name' => 'Demo User', 'password' => Hash::make(Str::random(64))],
        );
    }

    private function seedDemoData(): void
    {
        $lastMonth = CarbonImmutable::now('UTC')->startOfMonth()->subMonth();
        $thisMonth = $lastMonth->addMonth();

        $templateEn = ReportTemplate::create([
            'name' => 'Generic Monthly Report',
            'language' => Language::English,
            'title_format' => '{project} Monthly Report — {period}',
            'sections' => [
                ['key' => 'summary', 'title' => 'Summary'],
                ['key' => 'completed', 'title' => 'Completed Work'],
                ['key' => 'ongoing', 'title' => 'Ongoing Work'],
                ['key' => 'waiting', 'title' => 'Waiting / Blocked'],
            ],
            'blade_view' => 'reports.templates.generic',
        ]);

        ReportTemplate::create([
            'name' => 'Laporan Bulanan Umum',
            'language' => Language::Indonesian,
            'title_format' => 'Laporan Bulanan {project} — {period}',
            'sections' => [
                ['key' => 'summary', 'title' => 'Ringkasan'],
                ['key' => 'completed', 'title' => 'Pekerjaan Selesai'],
                ['key' => 'ongoing', 'title' => 'Pekerjaan Berjalan'],
                ['key' => 'waiting', 'title' => 'Menunggu / Terhambat'],
            ],
            'blade_view' => 'reports.templates.generic',
        ]);

        $harbor = Project::create([
            'name' => 'Harbor Logistics Portal',
            'slug' => 'harbor-logistics-portal',
            'aliases' => ['HLP', 'harbor'],
            'description' => 'Fictional shipment tracking portal used for demo data.',
            'default_language' => Language::English,
            'report_template_id' => $templateEn->id,
        ]);

        $kedai = Project::create([
            'name' => 'Kedai Senja App',
            'slug' => 'kedai-senja-app',
            'aliases' => ['KS', 'kedai'],
            'description' => 'Fictional coffee shop ordering app used for demo data.',
        ]);

        $rina = Person::create(['name' => 'Rina', 'aliases' => ['Bu Rina'], 'notes' => 'Fictional client-side PM.']);
        $marco = Person::create(['name' => 'Marco', 'aliases' => [], 'notes' => 'Fictional vendor contact.']);
        $budi = Person::create(['name' => 'Budi', 'aliases' => ['Pak Budi'], 'notes' => null]);

        $payment = $this->seedTask($harbor, 'Payment gateway integration', [
            [$lastMonth->addDays(19), TaskStatus::Open, null, ActivityType::Request, 'Request received from Rina to integrate the payment gateway.'],
            [$lastMonth->addDays(20), TaskStatus::InProgress, null, ActivityType::Development, 'Started the sandbox integration.'],
            [$lastMonth->addDays(27), TaskStatus::Waiting, WaitingReason::Vendor, ActivityType::Communication, 'Waiting for Marco to enable production credentials.'],
            [$thisMonth->addDays(2), TaskStatus::InProgress, null, ActivityType::Configuration, 'Production credentials received and configured.'],
            [$thisMonth->addDays(11), TaskStatus::Completed, null, ActivityType::Milestone, 'Payment gateway live in production.'],
        ], priority: TaskPriority::High);
        $payment->people()->attach($rina, ['role' => TaskPersonRole::Requester]);
        $payment->people()->attach($marco, ['role' => TaskPersonRole::Stakeholder]);

        $this->seedTask($harbor, 'Staging server hardening', [
            [$thisMonth->addDays(3), TaskStatus::Open, null, ActivityType::Request, 'Security review asked for staging hardening.'],
            [$thisMonth->addDays(4), TaskStatus::InProgress, null, ActivityType::Configuration, 'Disabled password SSH login and enabled the firewall.'],
        ]);

        $courier = $this->seedTask($harbor, 'Courier API rate limit issue', [
            [$thisMonth->addDays(5), TaskStatus::Open, null, ActivityType::Investigation, 'Tracking updates fail during peak hours.'],
            [$thisMonth->addDays(6), TaskStatus::InProgress, null, ActivityType::Investigation, 'Confirmed HTTP 429 responses from the courier API.'],
            [$thisMonth->addDays(7), TaskStatus::Waiting, WaitingReason::Api, ActivityType::FollowUp, 'Asked the courier to raise our rate limit.'],
        ], type: 'bug');
        $courier->people()->attach($marco, ['role' => TaskPersonRole::Assignee]);

        $this->seedTask($harbor, 'Invoice PDF layout', [
            [$thisMonth->addDays(8), TaskStatus::Draft, null, ActivityType::Other, 'Maybe adjust the invoice PDF layout.'],
        ]);

        $menu = $this->seedTask($kedai, 'Menu sync with POS', [
            [$lastMonth->addDays(24), TaskStatus::Open, null, ActivityType::Request, 'Budi asked to sync the menu with the POS system.'],
            [$lastMonth->addDays(25), TaskStatus::InProgress, null, ActivityType::Development, 'Built the menu import job.'],
            [$thisMonth->addDays(1), TaskStatus::Blocked, null, ActivityType::Blocker, 'POS export format changed without notice.'],
        ]);
        $menu->people()->attach($budi, ['role' => TaskPersonRole::Requester]);

        $this->seedTask($kedai, 'App store listing', [
            [$lastMonth->addDays(10), TaskStatus::Open, null, ActivityType::Request, 'Prepare the app store listing.'],
            [$lastMonth->addDays(15), TaskStatus::Cancelled, null, ActivityType::Communication, 'Listing postponed by the client; task cancelled.'],
        ]);

        $this->seedTask($kedai, 'Loyalty points MVP', [
            [$thisMonth->addDays(9), TaskStatus::Open, null, ActivityType::Request, 'Scope the loyalty points MVP.'],
        ], priority: TaskPriority::Low);

        $failed = $this->message('catat: update dns utk portal', $thisMonth->addDays(10), InboundMessageStatus::Failed);
        $failed->update(['error' => 'Demo: AI output failed schema validation.']);
        $this->message('yang kemarin sudah beres', $thisMonth->addDays(10), InboundMessageStatus::NeedsClarification);

        $corrected = $payment->activities()->latest('activity_date')->firstOrFail();
        Correction::create([
            'inbound_message_id' => $corrected->inbound_message_id,
            'correction_type' => CorrectionType::ChangeStatus,
            'before' => ['status' => 'in_progress', 'waiting_reason' => null],
            'after' => ['status' => 'completed', 'waiting_reason' => null],
        ]);

        AiInteraction::create([
            'project_id' => $harbor->id,
            'inbound_message_id' => $corrected->inbound_message_id,
            'purpose' => 'worklog_extraction',
            'model' => 'fake-model',
            'prompt_version' => 'worklog_extraction/v1',
            'input' => ['messages' => [['role' => 'user', 'content' => $corrected->summary]]],
            'output' => '{"items":[]}',
            'tokens_input' => 420,
            'tokens_output' => 95,
            'latency_ms' => 1200,
            'success' => true,
        ]);

        ReminderRule::create([
            'type' => ReminderType::DailyWorklog,
            'schedule' => ['time' => '18:00', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri']],
        ]);

        ReminderRule::create([
            'type' => ReminderType::MonthlyReport,
            'schedule' => ['day' => 'last', 'time' => '09:00'],
        ]);
    }

    /**
     * Create a task and replay its history: one activity per step, plus created /
     * status_changed events validated against the transition matrix.
     *
     * @param  list<array{0: CarbonImmutable, 1: TaskStatus, 2: ?WaitingReason, 3: ActivityType, 4: string}>  $steps
     */
    private function seedTask(Project $project, string $title, array $steps, TaskPriority $priority = TaskPriority::Normal, ?string $type = null): Task
    {
        [$createdAt, $status, $reason] = $steps[0];

        $task = Task::create([
            'project_id' => $project->id,
            'title' => $title,
            'type' => $type,
            'status' => $status,
            'waiting_reason' => $reason,
            'priority' => $priority,
        ]);
        $task->forceFill(['created_at' => $createdAt])->save();

        $previous = null;

        foreach ($steps as [$at, $status, $reason, $activityType, $summary]) {
            $message = $this->message($summary, $at, InboundMessageStatus::Processed);

            if ($previous === null) {
                $this->event($task, TaskEventType::Created, null, $this->state($status, $reason), $message, $at);
            } else {
                TaskStatusTransition::assertCanTransition($previous[0], $status);
                $this->event($task, TaskEventType::StatusChanged, $this->state(...$previous), $this->state($status, $reason), $message, $at);
            }

            Activity::create([
                'task_id' => $task->id,
                'project_id' => $project->id,
                'inbound_message_id' => $message->id,
                'activity_type' => $activityType,
                'summary' => $summary,
                'activity_date' => $at->toDateString(),
                'source' => ActivitySource::Telegram,
            ]);

            $previous = [$status, $reason];
        }

        $last = end($steps);
        $task->update([
            'status' => $last[1],
            'waiting_reason' => $last[2],
            'started_at' => collect($steps)->first(fn (array $s) => $s[1] === TaskStatus::InProgress)[0] ?? null,
            'completed_at' => $last[1] === TaskStatus::Completed ? $last[0] : null,
            'last_activity_at' => $last[0],
        ]);

        return $task;
    }

    /**
     * @return array{status: string, waiting_reason: ?string}
     */
    private function state(TaskStatus $status, ?WaitingReason $reason): array
    {
        return ['status' => $status->value, 'waiting_reason' => $reason?->value];
    }

    /**
     * @param  array<string, mixed>|null  $from
     * @param  array<string, mixed>  $to
     */
    private function event(Task $task, TaskEventType $type, ?array $from, array $to, InboundMessage $message, CarbonImmutable $at): void
    {
        (new TaskEvent([
            'task_id' => $task->id,
            'event_type' => $type,
            'from_value' => $from,
            'to_value' => $to,
            'actor' => EventActor::Ai,
            'inbound_message_id' => $message->id,
        ]))->forceFill(['created_at' => $at])->save();
    }

    private function message(string $text, CarbonImmutable $at, InboundMessageStatus $status): InboundMessage
    {
        $messageId = $this->nextMessageId++;

        return InboundMessage::create([
            'source' => MessageSource::Telegram,
            'idempotency_key' => 'telegram:'.self::DEMO_CHAT_ID.':'.$messageId,
            'telegram_chat_id' => self::DEMO_CHAT_ID,
            'telegram_message_id' => $messageId,
            'text' => $text,
            'received_at' => $at,
            'status' => $status,
        ]);
    }
}
