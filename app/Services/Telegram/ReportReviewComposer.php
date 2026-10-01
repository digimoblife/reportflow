<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\ReportVersion;
use Illuminate\Support\Facades\Lang;

/**
 * The Telegram review message of a report version (PRD §42): a short summary and the buttons Approve, Regenerate, Edit via
 * instruction, Open in Dashboard, Cancel. Persona wording from the lang files; the numbers come from the version.
 */
class ReportReviewComposer
{
    public function __construct(private readonly BotMessages $messages) {}

    /**
     * @return array{text: string, keyboard: list<list<array<string, string>>>}
     */
    public function review(Report $report, ReportVersion $version, Language $language, string $timezone): array
    {
        $lang = $language->value;
        $c = (array) ($version->content['counts'] ?? []);
        $label = fn (string $key): string => (string) Lang::get('ui.report_review.'.$key, [], $lang);
        $fallbacks = count(array_filter((array) ($version->content['sections'] ?? []), fn ($s): bool => (bool) ($s['fallback'] ?? false)));

        $lines = [
            $this->messages->get($report->status === ReportStatus::Approved ? 'report.review_approved' : 'report.review_intro', $language),
            '',
            '📄 '.(string) ($version->content['title'] ?? ''),
            '🔖 v'.$version->version_no.' · '.str_replace(':at', $version->data_snapshot_at->copy()->setTimezone($timezone)->format('d M Y H:i'), $label('snapshot')),
            '',
            '📊 '.$label('activities').': '.(int) ($c['activities'] ?? 0),
            '✅ '.$label('completed').': '.(int) ($c['completed'] ?? 0),
            '🔧 '.$label('ongoing').': '.(int) ($c['ongoing'] ?? 0),
            '⏳ '.$label('waiting').': '.(int) ($c['waiting'] ?? 0),
            '↪️ '.$label('cross_month').': '.(int) ($c['cross_month'] ?? 0),
            '⚠️ '.$label('incidents').': '.(int) ($c['incidents'] ?? 0),
        ];

        if ($fallbacks > 0) {
            $lines[] = '';
            $lines[] = 'ℹ️ '.str_replace(':count', (string) $fallbacks, $label('fallback_note'));
        }

        return ['text' => implode("\n", $lines), 'keyboard' => $this->keyboard($report, $version, $language)];
    }

    /**
     * @return list<list<array<string, string>>>
     */
    public function keyboard(Report $report, ReportVersion $version, Language $language): array
    {
        $lang = $language->value;
        $btn = fn (string $label, string $action, ?int $arg = null): array => [
            'text' => (string) Lang::get('ui.report_review.buttons.'.$label, [], $lang),
            'callback_data' => (new ReportCallback($report->id, $version->version_no, $action, $arg))->encode(),
        ];
        $rows = [];
        $url = $this->dashboardUrl($report);

        if ($report->status === ReportStatus::InReview) {
            $rows[] = [$btn('approve', 'ok'), $btn('regenerate', 're')];
            $rows[] = [$btn('edit', 'ed')];
        }

        if ($url !== null) {
            $rows[] = [['text' => (string) Lang::get('ui.report_review.buttons.dashboard', [], $lang), 'url' => $url]];
        }

        if (! in_array($report->status, [ReportStatus::Approved, ReportStatus::Cancelled, ReportStatus::Generating], true)) {
            $rows[] = [$btn('cancel', 'cx')];
        }

        return $rows;
    }

    /**
     * @return array{text: string, keyboard: list<list<array<string, string>>>}
     */
    public function sectionMenu(Report $report, ReportVersion $version, Language $language): array
    {
        $lang = $language->value;
        $rows = [];

        foreach (array_values((array) ($version->content['sections'] ?? [])) as $i => $section) {
            $rows[] = [['text' => (string) $section['title'], 'callback_data' => (new ReportCallback($report->id, $version->version_no, 'sec', $i))->encode()]];
        }

        $rows[] = [['text' => (string) Lang::get('ui.report_review.buttons.back', [], $lang), 'callback_data' => (new ReportCallback($report->id, $version->version_no, 'bk'))->encode()]];

        return ['text' => $this->messages->get('report.edit_pick', $language), 'keyboard' => $rows];
    }

    /**
     * "Open in dashboard" is offered only when Telegram would accept the link: https and a real host.
     */
    public function dashboardUrl(Report $report): ?string
    {
        $url = ReportResource::getUrl('view', ['record' => $report], panel: 'admin');
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://') || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        return $url;
    }
}
