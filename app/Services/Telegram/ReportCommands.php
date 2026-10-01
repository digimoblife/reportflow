<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Jobs\SendReportReview;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Services\Report\ReportFactsBuilder;
use App\Services\Report\ReportGenerator;
use App\Services\Report\ReportRequests;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;

/**
 * `/report`, `/review` and `/reports` (PRD §20, §42), and the project picker behind `/report`. Starting a report goes
 * through ReportRequests like the dashboard does; this class only decides what to ask and what to say.
 */
class ReportCommands
{
    public function __construct(
        private readonly TelegramMessenger $messenger,
        private readonly BotMessages $messages,
        private readonly ReportGenerator $generator,
        private readonly ReportRequests $requests,
        private readonly ReportFactsBuilder $facts,
    ) {}

    /**
     * `/report [YYYY-MM]`: the month to report is the one given, else the current month from day 25 on, else the last one.
     * Projects with no activity in it are not offered; with several, the person picks.
     */
    public function start(User $user, int $chatId, Language $language, #[\SensitiveParameter] ?string $argument): void
    {
        $month = $this->month($user, $argument);

        if ($month === null) {
            $this->messenger->trySend($chatId, $this->messages->get('report.start_usage', $language));

            return;
        }

        [$from, $to] = $this->range($month);
        $projects = [];

        foreach (Project::query()->where('status', 'active')->orderBy('name')->get() as $project) {
            if (Activity::query()->where('project_id', $project->id)->whereBetween('activity_date', [$from, $to])->exists()) {
                $projects[] = $project;
            }
        }

        if ($projects === []) {
            $this->messenger->trySend($chatId, $this->messages->get('report.no_activity', $language, ['month' => $this->monthLabel($month, $language)]));

            return;
        }

        if (count($projects) === 1) {
            $this->begin($user, $chatId, $language, $projects[0], $month);

            return;
        }

        $this->messenger->trySend($chatId, $this->messages->get('report.pick_project', $language, ['month' => $this->monthLabel($month, $language)]), null, array_map(
            fn (Project $p): array => [['text' => '📁 '.mb_substr($p->name, 0, 40), 'callback_data' => (new ReportStartCallback($p->id, $month))->encode()]],
            array_slice($projects, 0, 10),
        ));
    }

    /** The person picked a project for the month. */
    public function startFromButton(ReportStartCallback $data, TelegramUpdate $update, User $user, Language $language): void
    {
        $this->messenger->tryAnswer((string) $update->callbackId);
        $project = Project::query()->find($data->projectId);

        if ($project !== null) {
            $this->begin($user, $update->chatId, $language, $project, $data->month);
        }
    }

    /**
     * Asks about entries that are still being processed (PRD §23), or about an already approved report, before
     * generating; otherwise queues the generation right away.
     */
    public function begin(User $user, int $chatId, Language $language, Project $project, string $month): void
    {
        [$from, $to] = $this->range($month);
        $reportLanguage = $project->default_language ?? $user->default_language;
        $report = $this->generator->findOrCreate($project, $this->requests->typeOf($from, $to), $from, $to, $reportLanguage);

        if ($report->status === ReportStatus::Approved) {
            $this->messenger->trySend($chatId, $this->messages->get('report.already_approved', $language, ['period' => $this->monthLabel($month, $language)]), null, [[
                $this->button($report, 'nv', 'new_version', $language),
                $this->button($report, 'ig', 'dismiss', $language),
            ]]);

            return;
        }

        $pending = $this->generator->pendingEntries();

        if ($pending > 0) {
            $this->messenger->trySend($chatId, $this->messages->get('report.pending_entries', $language, ['count' => $pending]), null, [[
                $this->button($report, 'wt', 'wait', $language),
                $this->button($report, 'sk', 'skip', $language),
            ]]);

            return;
        }

        $this->generate($report, $chatId, $language, false);
    }

    /** Queues the generation and tells the person (or that it is already running). */
    public function generate(Report $report, int $chatId, Language $language, bool $wait): void
    {
        $result = $this->requests->request($report->project, $report->period_start->format('Y-m-d'), $report->period_end->format('Y-m-d'), $report->language, $wait, 'telegram');

        $this->messenger->trySend($chatId, $this->messages->get($result['status'] === ReportRequests::QUEUED ? 'report.generating' : 'report.busy', $language));
    }

    /** Re-sends the review (summary + PDF) of the newest report that is waiting for review. */
    public function review(User $user, int $chatId, Language $language): void
    {
        $report = Report::query()->where('status', ReportStatus::InReview)->whereNotNull('current_version_id')->orderByDesc('updated_at')->first();

        if ($report === null) {
            $this->messenger->trySend($chatId, $this->messages->get('report.nothing_to_review', $language));

            return;
        }

        SendReportReview::dispatch((int) $report->current_version_id, $user->id);
    }

    /** The latest reports with their status. */
    public function reports(User $user, int $chatId, Language $language): void
    {
        $reports = Report::query()->with(['project', 'currentVersion'])->where('status', '!=', ReportStatus::Cancelled)->orderByDesc('updated_at')->limit(6)->get();

        if ($reports->isEmpty()) {
            $this->messenger->trySend($chatId, $this->messages->get('report.none', $language));

            return;
        }

        $lines = [$this->messages->get('report.list_title', $language), ''];

        foreach ($reports as $report) {
            $lines[] = '📄 '.$report->project->name.' · '.$report->period_start->format('d M Y').' – '.$report->period_end->format('d M Y')
                .' · '.(string) Lang::get('ui.dashboard.reports.statuses.'.$report->status->value, [], $language->value)
                .($report->currentVersion === null ? '' : ' (v'.$report->currentVersion->version_no.')');
        }

        $lines[] = '';
        $lines[] = $this->messages->get('report.list_hint', $language);

        $this->messenger->trySend($chatId, implode("\n", $lines));
    }

    /**
     * The month to report as YYYY-MM, or null when the argument is not a month.
     */
    public function month(User $user, ?string $argument, ?CarbonImmutable $now = null): ?string
    {
        $argument = $argument === null ? null : trim($argument);

        if ($argument !== null && $argument !== '') {
            return preg_match('/^(20[0-9]{2})-(0[1-9]|1[0-2])$/', $argument) === 1 ? $argument : null;
        }

        $local = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($user->timezone);

        return ($local->day >= 25 ? $local : $local->subMonthNoOverflow())->format('Y-m');
    }

    /**
     * @return array{0: string, 1: string} first and last day of the month
     */
    private function range(string $month): array
    {
        $first = CarbonImmutable::parse($month.'-01');

        return [$first->format('Y-m-d'), $first->endOfMonth()->format('Y-m-d')];
    }

    private function monthLabel(string $month, Language $language): string
    {
        return $this->facts->periodLabel(ReportType::Monthly, $month.'-01', CarbonImmutable::parse($month.'-01')->endOfMonth()->format('Y-m-d'), $language);
    }

    /**
     * @return array<string, string>
     */
    private function button(Report $report, string $action, string $label, Language $language): array
    {
        return [
            'text' => (string) Lang::get('ui.report_review.buttons.'.$label, [], $language->value),
            'callback_data' => (new ReportCallback($report->id, 0, $action))->encode(),
        ];
    }
}
