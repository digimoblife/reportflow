<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Jobs\SendReportReview;
use App\Models\Report;
use App\Models\ReportVersion;
use App\Models\User;
use App\Services\Report\ReportEditor;
use App\Services\Report\ReportRequests;
use App\Services\Report\ReportWorkflow;
use App\Services\Report\StaleReportException;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

/**
 * The review conversation about a report in Telegram (PRD §42): the buttons under the review message and the one text
 * message that carries an edit instruction. Everything goes through the same services as the dashboard
 * (ReportWorkflow, ReportEditor, ReportRequests), so the channels cannot disagree (CLAUDE.md rule 2).
 */
class ReportReviewHandler
{
    public function __construct(
        private readonly TelegramMessenger $messenger,
        private readonly BotMessages $messages,
        private readonly ReportReviewComposer $composer,
        private readonly ReportWorkflow $workflow,
        private readonly ReportEditor $editor,
        private readonly ReportRequests $requests,
        private readonly AwaitingReportInstruction $awaiting,
    ) {}

    public function register(CallbackRouter $router): void
    {
        $router->onReport(fn (ReportCallback $data, TelegramUpdate $update, User $user, Language $language) => $this->handle($data, $update, $user, $language));
    }

    public function handle(ReportCallback $data, TelegramUpdate $update, User $user, Language $language): void
    {
        $callbackId = (string) $update->callbackId;
        $report = Report::query()->with(['project', 'currentVersion'])->find($data->reportId);

        if ($report === null) {
            $this->messenger->tryAnswer($callbackId, $this->messages->get('callback.expired', $language));

            return;
        }

        $current = $report->currentVersion;

        // Choices that are not about a particular version: "wait / skip entries" and "new version / dismiss".
        if (in_array($data->action, ['wt', 'sk', 'nv', 'ig'], true)) {
            $this->messenger->tryAnswer($callbackId);
            $this->unversioned($data, $report, $update, $language);

            return;
        }

        // A press made on an older review: show the newest instead of acting on something the person no longer sees.
        if ($current === null || $current->version_no !== $data->versionNo) {
            $this->messenger->tryAnswer($callbackId, $this->messages->get('report.stale', $language));
            $current !== null && $this->show($update, $this->composer->review($report, $current, $language, $user->timezone));

            return;
        }

        $this->messenger->tryAnswer($callbackId);

        match ($data->action) {
            'ok' => $this->approve($report, $current, $update, $user, $language),
            're' => $this->regenerate($report, $update, $language),
            'ed' => $this->show($update, $this->composer->sectionMenu($report, $current, $language)),
            'sec' => $this->askInstruction($report, $current, $data->arg ?? -1, $update, $user, $language),
            'bk' => $this->back($report, $current, $update, $user, $language),
            'cx' => $this->cancel($report, $update, $language),
            default => null,
        };
    }

    private function unversioned(ReportCallback $data, Report $report, TelegramUpdate $update, Language $language): void
    {
        if ($data->action === 'ig') {
            $this->workflow->dismissDrift($report);
            $this->edit($update, $this->messages->get('report.dismissed', $language), []);

            return;
        }

        $result = $this->requests->request($report->project, $report->period_start->format('Y-m-d'), $report->period_end->format('Y-m-d'), $report->language, $data->action === 'wt', 'telegram');

        $this->edit($update, $this->messages->get($result['status'] === ReportRequests::QUEUED ? 'report.generating' : 'report.busy', $language), []);
    }

    /**
     * The text message that follows "Edit via instruksi": the instruction for the chosen section. $text is already redacted.
     */
    public function instruction(User $user, int $chatId, Language $language, #[\SensitiveParameter] string $text): bool
    {
        $state = $this->awaiting->get($user->id);

        if ($state === null) {
            return false;
        }

        $this->awaiting->clear($user->id);
        $report = Report::query()->with('currentVersion')->find($state['report_id']);
        $current = $report?->currentVersion;

        if ($report === null || $current === null || $current->version_no !== $state['version_no']) {
            $this->messenger->trySend($chatId, $this->messages->get('report.stale', $language));

            return true;
        }

        try {
            $result = $this->editor->instruct($report, $current->id, $state['section'], $text, 'telegram');
        } catch (StaleReportException) {
            $this->messenger->trySend($chatId, $this->messages->get('report.stale', $language));

            return true;
        } catch (InvalidArgumentException) {
            $this->messenger->trySend($chatId, $this->messages->get('report.not_possible', $language));

            return true;
        }

        if ($result->ok() && $result->version !== null) {
            $this->messenger->trySend($chatId, $this->messages->get('report.instructed', $language, ['count' => $result->factsSaved]));
            SendReportReview::dispatch($result->version->id, $user->id);

            return true;
        }

        $this->messenger->trySend($chatId, $this->messages->get('report.instruction.'.$result->status, $language, ['facts' => implode('; ', $result->unmatched)]));

        return true;
    }

    private function approve(Report $report, ReportVersion $current, TelegramUpdate $update, User $user, Language $language): void
    {
        try {
            $this->workflow->approve($report, $current->id);
        } catch (StaleReportException|InvalidArgumentException) {
            $this->edit($update, $this->messages->get('report.not_possible', $language), []);

            return;
        }

        $report->refresh();
        $this->edit($update, $this->messages->get('report.approved', $language), []);
        SendReportReview::dispatch($current->id, $user->id, SendReportReview::FILES);
    }

    private function regenerate(Report $report, TelegramUpdate $update, Language $language): void
    {
        $result = $this->requests->request($report->project, $report->period_start->format('Y-m-d'), $report->period_end->format('Y-m-d'), $report->language, false, 'telegram');

        $this->edit($update, $this->messages->get($result['status'] === ReportRequests::QUEUED ? 'report.generating' : 'report.busy', $language), []);
    }

    private function askInstruction(Report $report, ReportVersion $current, int $index, TelegramUpdate $update, User $user, Language $language): void
    {
        $section = array_values((array) ($current->content['sections'] ?? []))[$index] ?? null;

        if ($section === null) {
            return;
        }

        $this->awaiting->await($user->id, $report->id, $current->version_no, (string) $section['key']);
        $this->edit($update, $this->messages->get('report.edit_prompt', $language, ['section' => (string) $section['title']]), [[[
            'text' => (string) Lang::get('ui.report_review.buttons.back', [], $language->value),
            'callback_data' => (new ReportCallback($report->id, $current->version_no, 'bk'))->encode(),
        ]]]);
    }

    private function back(Report $report, ReportVersion $current, TelegramUpdate $update, User $user, Language $language): void
    {
        $this->awaiting->clear($user->id);
        $this->show($update, $this->composer->review($report, $current, $language, $user->timezone));
    }

    private function cancel(Report $report, TelegramUpdate $update, Language $language): void
    {
        try {
            $this->workflow->cancel($report);
        } catch (InvalidArgumentException) {
            $this->edit($update, $this->messages->get('report.not_possible', $language), []);

            return;
        }

        $this->edit($update, $this->messages->get('report.cancelled', $language), []);
    }

    /**
     * @param  array{text: string, keyboard: list<list<array<string, string>>>}  $view
     */
    private function show(TelegramUpdate $update, array $view): void
    {
        $this->edit($update, $view['text'], $view['keyboard']);
    }

    /**
     * @param  list<list<array<string, string>>>  $keyboard
     */
    private function edit(TelegramUpdate $update, string $text, array $keyboard): void
    {
        try {
            $this->messenger->edit($update->chatId, $update->messageId, $text, $keyboard);
        } catch (TelegramApiException $e) {
            if (! $e->messageNotModified()) {
                $this->messenger->trySend($update->chatId, $text, null, $keyboard === [] ? null : $keyboard);
            }
        }
    }
}
