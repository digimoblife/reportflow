<?php

namespace App\Services\Report;

use App\Enums\ActivitySource;
use App\Enums\ActivityType;
use App\Enums\ReportCreatedBy;
use App\Jobs\RenderReportFiles;
use App\Models\Activity;
use App\Models\Report;
use App\Models\ReportVersion;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiExtractionFailed;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AIService;
use App\Services\Ai\Schema\JsonSchemaValidator;
use App\Services\Redaction\RedactionService;
use App\Services\Worklog\TaskLifecycle;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * Edit a report section by instruction, and save facts typed into a section as activities (PRD §42, CLAUDE.md rule 9).
 *
 * The rule: a NEW FACT in an instruction ("downtime lasted 25 minutes") is stored as an activity (`source = report_edit`)
 * first, and only then does the section get rewritten from the data, so the report can never state something the archive
 * does not hold. The steps that call the AI run before anything is written; the activities and the new version are then
 * saved in ONE transaction, so a failure leaves neither behind. Instructions are redacted before they are stored or sent.
 * Needs a UserContext.
 */
class ReportEditor
{
    public const MAX_INSTRUCTION = 1000;

    private ?JsonSchemaValidator $schema = null;

    public function __construct(
        private readonly UserContext $context,
        private readonly ReportDataSelector $selector,
        private readonly ReportGenerator $generator,
        private readonly ReportTemplates $templates,
        private readonly ReportWorkflow $workflow,
        private readonly AIService $ai,
        private readonly TaskLifecycle $lifecycle,
    ) {}

    /**
     * @throws StaleReportException when the report moved on since $expectedVersionId
     * @throws InvalidArgumentException when the report cannot be edited now
     */
    public function instruct(Report $report, int $expectedVersionId, string $sectionKey, string $instruction, string $channel = 'dashboard'): InstructionResult
    {
        $instruction = trim($instruction);

        if (mb_strlen($instruction) < 3 || mb_strlen($instruction) > self::MAX_INSTRUCTION) {
            return new InstructionResult(InstructionResult::INVALID);
        }

        $redaction = RedactionService::forUser($this->context->requireUserId())->redact($instruction);

        if ($redaction->failed()) {
            return new InstructionResult(InstructionResult::REDACTION_FAILED);
        }

        $clean = $redaction->text;
        $report = Report::query()->with('project')->findOrFail($report->id);
        $this->workflow->lock($report, $expectedVersionId);   // not a transaction: only the early stale/status check

        $current = ReportVersion::query()->findOrFail($expectedVersionId);
        $template = collect($this->templates->sections($report->language))->firstWhere('key', $sectionKey);
        $existing = collect((array) $current->content['sections'])->firstWhere('key', $sectionKey);

        if ($template === null || $existing === null) {
            return new InstructionResult(InstructionResult::INVALID);
        }

        $user = User::query()->findOrFail($this->context->requireUserId());
        $data = $this->selector->select($report->project, $report->period_start->format('Y-m-d'), $report->period_end->format('Y-m-d'), $user->timezone, CarbonImmutable::instance($current->data_snapshot_at), $current->source_activity_ids);
        $today = CarbonImmutable::now($user->timezone);

        // 1. the model finds the new facts (nothing is written yet)
        try {
            $interpreted = $this->ai->interpretReportInstruction(
                $this->interpretPayload($report, $sectionKey, (string) $existing['title'], $clean, $data, $today),
                fn (array $decoded): array => $this->factErrors($decoded, $data, $today),
                $report->id,
                $report->project_id,
            )->data;
        } catch (AiExtractionFailed|AiProviderException|JsonException) {
            return new InstructionResult(InstructionResult::AI_FAILED);
        }

        $facts = (array) $interpreted['new_facts'];
        $unmatched = array_values(array_map('strval', (array) $interpreted['unmatched_facts']));

        if ($unmatched !== []) {
            return new InstructionResult(InstructionResult::UNMATCHED, unmatched: $unmatched);
        }

        if (! $template['narrative'] && $facts === []) {
            return new InstructionResult(InstructionResult::NOTHING_TO_CHANGE);
        }

        // 2. the section is rewritten from the data as it WILL be once the facts are stored (still nothing written)
        $pending = [];

        foreach (array_values($facts) as $i => $fact) {
            $pending[] = ['id' => -($i + 1), 'task_id' => (int) $fact['task_id'], 'date' => (string) $fact['date'], 'type' => (string) $fact['activity_type'], 'summary' => (string) $fact['summary'], 'source' => ActivitySource::ReportEdit->value];
        }

        $extended = $data->withActivities($pending);
        $rewritten = $this->generator->composeSections($report, $extended, $sectionKey, $clean)[0];

        if ($template['narrative'] && $rewritten['fallback']) {
            return new InstructionResult(InstructionResult::REWRITE_FAILED);
        }

        // 3. facts and the new version, together or not at all
        $version = DB::transaction(function () use ($report, $expectedVersionId, $current, $facts, $rewritten, $extended, $user, $channel, $clean, $sectionKey): ReportVersion {
            $locked = $this->workflow->lock($report, $expectedVersionId);
            $ids = [];

            foreach ($facts as $fact) {
                $ids[] = $this->recordFact($report->project_id, (int) $fact['task_id'], (string) $fact['date'], (string) $fact['summary'], (string) $fact['activity_type'], $user->timezone)->id;
            }

            $content = $current->content;

            foreach ($content['sections'] as $i => $section) {
                if ($section['key'] === $sectionKey) {
                    $content['sections'][$i] = $rewritten;
                }
            }

            $content['counts'] = $extended->counts();

            return $this->workflow->append($locked, $current, $content, ReportCreatedBy::InstructionEdit, $channel, $clean, [...$current->source_activity_ids, ...$ids]);
        });

        RenderReportFiles::dispatch($version->id, $this->context->requireUserId());

        return new InstructionResult(InstructionResult::OK, $version, count($facts));
    }

    /**
     * Sections whose text now contains numbers the previous version did not: candidates for "save as activity too?"
     * after a manual edit (PRD §42). Only a hint for the person; nothing is written.
     *
     * @return list<string> section keys
     */
    public function factCandidates(ReportVersion $old, ReportVersion $new): array
    {
        $before = [];

        foreach ((array) $old->content['sections'] as $section) {
            $before[(string) $section['key']] = ReportSectionPayload::numbers((string) $section['markdown']);
        }

        $keys = [];

        foreach ((array) $new->content['sections'] as $section) {
            $added = array_diff(ReportSectionPayload::numbers((string) $section['markdown']), $before[(string) $section['key']] ?? []);

            if ($added !== []) {
                $keys[] = (string) $section['key'];
            }
        }

        return $keys;
    }

    /**
     * Saves a fact the person wrote into a report as an activity (`source = report_edit`), so the next report keeps it.
     * The task must be one of the report's tasks and the date inside its period.
     */
    public function saveFact(Report $report, int $taskId, string $date, string $summary, ActivityType $type): InstructionResult
    {
        $report = Report::query()->with('project')->findOrFail($report->id);
        $current = $report->current_version_id === null ? null : ReportVersion::query()->find($report->current_version_id);
        $user = User::query()->findOrFail($this->context->requireUserId());
        $redaction = RedactionService::forUser($user->id)->redact(trim($summary));
        $summary = trim($redaction->text);

        if ($current === null || $redaction->failed() || mb_strlen($summary) < 3 || mb_strlen($summary) > 300) {
            return new InstructionResult(InstructionResult::INVALID);
        }

        $data = $this->selector->select($report->project, $report->period_start->format('Y-m-d'), $report->period_end->format('Y-m-d'), $user->timezone, CarbonImmutable::instance($current->data_snapshot_at), $current->source_activity_ids);

        if (! isset($data->tasks[$taskId]) || ! $this->dateAllowed($date, $data, CarbonImmutable::now($user->timezone))) {
            return new InstructionResult(InstructionResult::INVALID);
        }

        DB::transaction(fn () => $this->recordFact($report->project_id, $taskId, $date, $summary, $type->value, $user->timezone));

        return new InstructionResult(InstructionResult::OK, factsSaved: 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function interpretPayload(Report $report, string $sectionKey, string $sectionTitle, string $instruction, ReportDataSet $data, CarbonImmutable $today): array
    {
        $tasks = [];

        foreach ($data->tasks as $id => $task) {
            $tasks[] = ['id' => $id, 'title' => $task['title'], 'status' => $task['status']];
        }

        $activities = [];

        foreach (array_slice($data->activities, 0, 80) as $a) {
            $activities[] = ['task_id' => $a['task_id'], 'date' => $a['date'], 'type' => $a['type'], 'summary' => mb_substr($a['summary'], 0, 240)];
        }

        return [
            'language' => $report->language->value,
            'section' => ['key' => $sectionKey, 'title' => $sectionTitle],
            'instruction' => $instruction,
            'period' => ['start' => $data->periodStart, 'end' => $data->periodEnd, 'default_date' => min($data->periodEnd, $today->format('Y-m-d'))],
            'tasks' => $tasks,
            'activities' => $activities,
        ];
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<string> error codes only (never the model's text)
     */
    private function factErrors(array $decoded, ReportDataSet $data, CarbonImmutable $today): array
    {
        $this->schema ??= JsonSchemaValidator::fromFile(resource_path('schemas/report_instruction.v1.json'));
        $errors = $this->schema->validate($decoded);

        if ($errors !== []) {
            return $errors;
        }

        foreach ((array) $decoded['new_facts'] as $fact) {
            if (! isset($data->tasks[(int) $fact['task_id']])) {
                $errors[] = 'new_facts:task_not_in_report';
            }

            if (! $this->dateAllowed((string) $fact['date'], $data, $today)) {
                $errors[] = 'new_facts:date_out_of_range';
            }
        }

        return array_values(array_unique($errors));
    }

    private function dateAllowed(string $date, ReportDataSet $data, CarbonImmutable $today): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date && $date >= $data->periodStart && $date <= $data->periodEnd && $date <= $today->format('Y-m-d');
    }

    private function recordFact(int $projectId, int $taskId, string $date, string $summary, string $type, string $timezone): Activity
    {
        $task = Task::query()->lockForUpdate()->findOrFail($taskId);

        $activity = Activity::query()->create([
            'task_id' => $task->id,
            'project_id' => $projectId,
            'inbound_message_id' => null,
            'activity_type' => ActivityType::from($type),
            'summary' => $summary,
            'content_structured' => [],
            'activity_date' => $date,
            'date_precision' => 'day',
            'source' => ActivitySource::ReportEdit,
        ]);

        $this->lifecycle->touchActivity($task, CarbonImmutable::parse($date, $timezone)->startOfDay());

        return $activity;
    }
}
