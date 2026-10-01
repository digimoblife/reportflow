<?php

namespace App\Jobs;

use App\Enums\ReportStatus;
use App\Jobs\Middleware\WithUserContext;
use App\Models\Report;
use App\Models\User;
use App\Services\Ops\OpsEvents;
use App\Services\Report\ReportDrift;
use App\Services\Report\ReportFactsBuilder;
use App\Services\Telegram\BotMessages;
use App\Services\Telegram\ReportCallback;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramMessenger;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

/**
 * "Laporan <periode> sudah di-approve, tetapi ada N perubahan. Buat versi baru?" (PRD §43). Sent once per detection (the
 * `outdated` transition is atomic and this job only runs after it); a retry does not repeat a message that went out.
 * Ids only.
 */
class SendReportOutdatedNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $reportId,
        public readonly int $userId,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new WithUserContext($this->userId)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(TelegramMessenger $messenger, BotMessages $messages, ReportDrift $drift, ReportFactsBuilder $facts): void
    {
        $report = Report::query()->with('currentVersion')->find($this->reportId);
        $user = User::query()->find($this->userId);

        if ($report === null || $user === null || $user->telegram_user_id === null || $report->status !== ReportStatus::Outdated || $report->currentVersion === null) {
            return;
        }

        $key = "report:outdated-notice:{$report->id}:{$report->currentVersion->id}:".($report->drift_dismissed_at?->getTimestamp() ?? 0);

        if (Cache::has($key)) {
            return;
        }

        $language = $user->default_language;
        $button = fn (string $label, string $action): array => [
            'text' => (string) Lang::get('ui.report_review.buttons.'.$label, [], $language->value),
            'callback_data' => (new ReportCallback($report->id, $report->currentVersion->version_no, $action))->encode(),
        ];

        try {
            $messenger->send($user->telegram_user_id, $messages->get('report.outdated', $language, [
                'period' => $facts->periodLabel($report->type, $report->period_start->format('Y-m-d'), $report->period_end->format('Y-m-d'), $language),
                'project' => $report->project->name,
                'count' => $drift->count($report),
            ]), null, [[$button('new_version', 'nv'), $button('dismiss', 'ig')]]);
        } catch (TelegramApiException $e) {
            if ($e->isRetryable()) {
                $this->release(max($e->retryAfter ?? 0, 30));

                return;
            }

            OpsEvents::record(OpsEvents::TELEGRAM_FAILED, ['method' => $e->apiMethod, 'status' => $e->httpStatus]);
            Log::warning('report.outdated_notice_undeliverable', ['report_id' => $report->id, 'method' => $e->apiMethod, 'status' => $e->httpStatus]);

            return;
        }

        Cache::put($key, true, 86_400 * 7);
    }
}
