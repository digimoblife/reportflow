<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Enums\ReportStatus;
use App\Jobs\SendReportReview;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Lang;

/**
 * `/review` and `/reports` (PRD §20, §42). Read-only apart from queueing a re-send of the review.
 */
class ReportCommands
{
    public function __construct(
        private readonly TelegramMessenger $messenger,
        private readonly BotMessages $messages,
    ) {}

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
}
