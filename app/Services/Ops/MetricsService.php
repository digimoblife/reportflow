<?php

namespace App\Services\Ops;

use App\Models\AiInteraction;
use App\Models\Correction;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\ReminderInstance;
use App\Models\ReportVersion;
use App\Models\SystemEvent;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * The numbers behind the Health page (PRD §73, §76, §78, §79) for the current user over a period. Everything comes from
 * tables that already exist plus `system_events`; nothing here reads message text. A value is null when there is nothing
 * to measure yet (no data is not "passing"). Needs a UserContext.
 */
class MetricsService
{
    public function __construct(private readonly UserContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ai = $this->ai($from, $to);
        $worklog = $this->worklog($from, $to);
        $reports = $this->reports($from, $to);
        $corrections = $this->corrections($from, $to);
        $t = (array) config('ops.targets');

        return [
            'from' => $from,
            'to' => $to,
            'ai' => $ai,
            'worklog' => $worklog,
            'corrections' => $corrections,
            'reports' => $reports,
            'reminders' => $this->reminders($from, $to),
            'queue' => $this->queue($from, $to),
            'telegram_failures' => $this->events(OpsEvents::TELEGRAM_FAILED, $from, $to),
            'costs' => $this->costs($from, $to),
            'targets' => [
                'correction_rate' => $this->check($corrections['rate'], $t['correction_rate_max'], max: true),
                'worklog_p95' => $this->check($worklog['p95_seconds'], $t['worklog_p95_seconds_max'], max: true),
                'report_success' => $this->check($reports['success_rate'], $t['report_success_min'], max: false),
                'pdf_success' => $this->check($reports['pdf_success_rate'], $t['pdf_success_min'], max: false),
                'ai_failure_rate' => $this->check($ai['failure_rate'], $t['ai_failure_rate_max'], max: true),
            ],
        ];
    }

    /**
     * @return array{requests: int, failed: int, failure_rate: float|null, p50_ms: int|null, p95_ms: int|null, tokens_input: int, tokens_output: int}
     */
    public function ai(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = AiInteraction::query()->whereBetween('created_at', [$from, $to])->selectRaw(
            'count(*) as requests, count(*) filter (where not success) as failed, coalesce(sum(tokens_input), 0) as tin, coalesce(sum(tokens_output), 0) as tout, '
            .'percentile_cont(0.5) within group (order by latency_ms) as p50, percentile_cont(0.95) within group (order by latency_ms) as p95'
        )->toBase()->first();

        $requests = (int) ($row->requests ?? 0);
        $failed = (int) ($row->failed ?? 0);

        return [
            'requests' => $requests,
            'failed' => $failed,
            'failure_rate' => $requests === 0 ? null : $failed / $requests,
            'p50_ms' => $row?->p50 === null ? null : (int) round((float) $row->p50),
            'p95_ms' => $row?->p95 === null ? null : (int) round((float) $row->p95),
            'tokens_input' => (int) ($row->tin ?? 0),
            'tokens_output' => (int) ($row->tout ?? 0),
        ];
    }

    /**
     * Notes that were processed, from arrival to done (queue wait included), first run only.
     *
     * @return array{processed: int, p50_seconds: float|null, p95_seconds: float|null}
     */
    public function worklog(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = InboundMessage::query()->whereNotNull('processed_at')->where('reprocess_count', 0)->whereBetween('received_at', [$from, $to])->selectRaw(
            'count(*) as n, percentile_cont(0.5) within group (order by extract(epoch from (processed_at - received_at))) as p50, '
            .'percentile_cont(0.95) within group (order by extract(epoch from (processed_at - received_at))) as p95'
        )->toBase()->first();

        return [
            'processed' => (int) ($row->n ?? 0),
            'p50_seconds' => $row?->p50 === null ? null : round((float) $row->p50, 1),
            'p95_seconds' => $row?->p95 === null ? null : round((float) $row->p95, 1),
        ];
    }

    /**
     * User Correction Rate (PRD §76): corrections divided by the interpretations the assistant applied (applied or later undone).
     *
     * @return array{applied: int, corrections: int, rate: float|null, by_type: array<string, int>}
     */
    public function corrections(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $userId = $this->context->requireUserId();

        $applied = (int) (DB::selectOne(
            "select count(*) as n from inbound_messages m cross join lateral jsonb_array_elements(m.outcome->'items') i "
            ."where m.user_id = ? and m.received_at between ? and ? and jsonb_typeof(m.outcome->'items') = 'array' and (i->>'state') in ('applied', 'undone')",
            [$userId, $from, $to],
        )->n ?? 0);

        $byType = [];

        foreach (Correction::query()->whereBetween('created_at', [$from, $to])->selectRaw('correction_type, count(*) as n')->groupBy('correction_type')->toBase()->get() as $row) {
            $byType[(string) $row->correction_type] = (int) $row->n;
        }

        $total = array_sum($byType);

        return ['applied' => $applied, 'corrections' => $total, 'rate' => $applied === 0 ? null : $total / $applied, 'by_type' => $byType];
    }

    /**
     * @return array{generated: int, failed: int, success_rate: float|null, avg_ms: int|null, p95_ms: int|null, pdf_failed: int, pdf_success_rate: float|null}
     */
    public function reports(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = ReportVersion::query()->where('created_by', 'ai_generate')->whereBetween('created_at', [$from, $to])->selectRaw(
            "count(*) as n, avg((content->'meta'->>'generation_ms')::numeric) as avg_ms, "
            ."percentile_cont(0.95) within group (order by (content->'meta'->>'generation_ms')::numeric) as p95_ms"
        )->toBase()->first();
        $versions = ReportVersion::query()->whereBetween('created_at', [$from, $to])->count();

        $generated = (int) ($row->n ?? 0);
        $failed = $this->events(OpsEvents::REPORT_FAILED, $from, $to);
        $pdfFailed = $this->events(OpsEvents::PDF_FAILED, $from, $to);

        return [
            'generated' => $generated,
            'failed' => $failed,
            'success_rate' => ($generated + $failed) === 0 ? null : $generated / ($generated + $failed),
            'avg_ms' => $row?->avg_ms === null ? null : (int) round((float) $row->avg_ms),
            'p95_ms' => $row?->p95_ms === null ? null : (int) round((float) $row->p95_ms),
            'pdf_failed' => $pdfFailed,
            'pdf_success_rate' => $versions === 0 ? null : max(0.0, 1 - $pdfFailed / $versions),
        ];
    }

    /**
     * @return array{sent: int, acknowledged: int, dismissed: int, snoozes: int, conversion: float|null}
     */
    public function reminders(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = fn () => ReminderInstance::query()->whereNotNull('sent_at')->whereBetween('sent_at', [$from, $to]);

        $sent = $base()->count();
        $acted = $base()->whereIn('status', ['acknowledged', 'completed'])->count();

        return [
            'sent' => $sent,
            'acknowledged' => $acted,
            'dismissed' => $base()->where('status', 'dismissed')->count(),
            'snoozes' => (int) $base()->sum('snooze_count'),
            'conversion' => $sent === 0 ? null : $acted / $sent,
        ];
    }

    /**
     * @return array{failed_total: int, failed_period: int, backlog: array<string, int>}
     */
    public function queue(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $backlog = [];

        foreach (['default', 'ai', 'reports'] as $queue) {
            try {
                $backlog[$queue] = (int) Queue::size($queue);
            } catch (Throwable) {
                $backlog[$queue] = 0;
            }
        }

        return [
            'failed_total' => DB::table('failed_jobs')->count(),
            'failed_period' => DB::table('failed_jobs')->whereBetween('failed_at', [$from, $to])->count(),
            'backlog' => $backlog,
        ];
    }

    public function events(string $type, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return SystemEvent::query()->where('type', $type)->whereBetween('created_at', [$from, $to])->count();
    }

    /**
     * Token use by purpose and by project (PRD §79). `estimate` is filled only when prices are configured for the model.
     *
     * @return array{by_purpose: list<array{purpose: string, requests: int, failed: int, tokens_input: int, tokens_output: int}>, by_project: list<array{project: string, tokens_input: int, tokens_output: int}>, estimate: float|null}
     */
    public function costs(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byPurpose = [];

        foreach (AiInteraction::query()->whereBetween('created_at', [$from, $to])->selectRaw('purpose, count(*) as n, count(*) filter (where not success) as failed, coalesce(sum(tokens_input), 0) as tin, coalesce(sum(tokens_output), 0) as tout')->groupBy('purpose')->orderBy('purpose')->toBase()->get() as $row) {
            $byPurpose[] = ['purpose' => (string) $row->purpose, 'requests' => (int) $row->n, 'failed' => (int) $row->failed, 'tokens_input' => (int) $row->tin, 'tokens_output' => (int) $row->tout];
        }

        $byProject = [];

        foreach (AiInteraction::query()->whereNotNull('project_id')->whereBetween('created_at', [$from, $to])->selectRaw('project_id, coalesce(sum(tokens_input), 0) as tin, coalesce(sum(tokens_output), 0) as tout')->groupBy('project_id')->toBase()->get() as $row) {
            $byProject[] = ['project' => (string) (Project::query()->whereKey((int) $row->project_id)->value('name') ?? '#'.$row->project_id), 'tokens_input' => (int) $row->tin, 'tokens_output' => (int) $row->tout];
        }

        return ['by_purpose' => $byPurpose, 'by_project' => $byProject, 'estimate' => $this->estimate($from, $to)];
    }

    /**
     * Money estimate from configured per-million prices; null when no price is configured (we do not guess a price).
     */
    private function estimate(CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $prices = (array) config('ai.pricing', []);
        $total = 0.0;
        $priced = false;

        foreach (AiInteraction::query()->whereBetween('created_at', [$from, $to])->selectRaw('model, coalesce(sum(tokens_input), 0) as tin, coalesce(sum(tokens_output), 0) as tout')->groupBy('model')->toBase()->get() as $row) {
            $price = $prices[$row->model] ?? null;

            if (is_array($price) && isset($price['input_per_million'], $price['output_per_million'])) {
                $total += ((int) $row->tin * (float) $price['input_per_million'] + (int) $row->tout * (float) $price['output_per_million']) / 1_000_000;
                $priced = true;
            }
        }

        return $priced ? round($total, 4) : null;
    }

    /**
     * @return array{value: float|int|null, target: float|int, ok: bool|null}
     */
    private function check(float|int|null $value, float|int $target, bool $max): array
    {
        return ['value' => $value, 'target' => $target, 'ok' => $value === null ? null : ($max ? $value <= $target : $value >= $target)];
    }
}
