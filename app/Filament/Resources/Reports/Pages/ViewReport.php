<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Enums\ActivityType;
use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\ReportResource;
use App\Jobs\SendReportReview;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\ReportVersion;
use App\Models\User;
use App\Services\Report\ReportDataSelector;
use App\Services\Report\ReportDiff;
use App\Services\Report\ReportDrift;
use App\Services\Report\ReportEditor;
use App\Services\Report\ReportHtml;
use App\Services\Report\ReportRequests;
use App\Services\Report\ReportWorkflow;
use App\Services\Report\SignedDownload;
use App\Services\Report\StaleReportException;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

/**
 * One report (PRD §22 page 4, §42): preview identical to the PDF, per-section Markdown editor, version history and
 * comparison, downloads, approve and cancel. Every change is guarded by the version the page was loaded with: if the
 * report moved on (another tab, a regeneration), the change is refused and nothing is overwritten (PRD §23).
 *
 * @extends ViewRecord<Report>
 */
class ViewReport extends ViewRecord
{
    protected static string $resource = ReportResource::class;

    protected string $view = 'filament.resources.reports.view-report';

    #[Locked]
    public int $loadedVersionId = 0;

    /** @var array<string, string> section key => Markdown being edited */
    public array $sections = [];

    /** @var array<string, string> section key => instruction being typed */
    public array $instructions = [];

    /** @var list<string> sections where the last manual save added numbers: offer "save as activity" */
    public array $factOffers = [];

    public ?string $factSection = null;

    public string $factTask = '';

    public string $factDate = '';

    public string $factSummary = '';

    public string $factType = 'other';

    /** Changes to the period's data since the snapshot (late entries, PRD §43). */
    public int $driftCount = 0;

    public bool $newerAvailable = false;

    public ?int $compareFrom = null;

    public ?int $compareTo = null;

    /** @var array<string, scalar> placeholders for the next notification */
    public array $notes = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->loadVersion($this->report()->current_version_id);
        $this->driftCount = app(ReportDrift::class)->count($this->report());
    }

    public function getTitle(): string|Htmlable
    {
        $version = $this->loadedVersion();

        return (string) ($version?->content['title'] ?? $this->report()->project->name);
    }

    public function report(): Report
    {
        /** @var Report $report */
        $report = $this->getRecord();

        return $report;
    }

    public function loadedVersion(): ?ReportVersion
    {
        return $this->loadedVersionId === 0 ? null : ReportVersion::query()->find($this->loadedVersionId);
    }

    /** Polled: follows generation progress, and never overwrites text the person is typing. */
    public function refreshState(): void
    {
        $this->record = Report::query()->with(['project', 'currentVersion'])->findOrFail($this->report()->id);
        $this->driftCount = app(ReportDrift::class)->count($this->report());
        $current = $this->report()->current_version_id;

        if ($current === null || $current === $this->loadedVersionId) {
            $this->newerAvailable = false;

            return;
        }

        if ($this->isDirty()) {
            $this->newerAvailable = true;
        } else {
            $this->loadVersion($current);
        }
    }

    public function loadNewest(): void
    {
        $this->record = Report::query()->with(['project', 'currentVersion'])->findOrFail($this->report()->id);
        $this->loadVersion($this->report()->current_version_id);
    }

    public function saveSections(): void
    {
        $this->guarded(function (ReportWorkflow $workflow): string {
            $before = $this->loadedVersion();
            $version = $workflow->saveEdit($this->report(), $this->loadedVersionId, $this->sections);

            if ($version === null) {
                return 'unchanged';
            }

            $this->loadVersion($version->id);
            $this->factOffers = $before === null ? [] : app(ReportEditor::class)->factCandidates($before, $version);

            return 'saved';
        });
    }

    /** "Edit via instruksi" for one section (PRD §42): new facts become activities first, then the section is rewritten. */
    public function instruct(string $key): void
    {
        $text = trim($this->instructions[$key] ?? '');

        $this->guarded(function () use ($key, $text): string {
            $result = app(ReportEditor::class)->instruct($this->report(), $this->loadedVersionId, $key, $text);

            if ($result->ok() && $result->version !== null) {
                $this->loadVersion($result->version->id);
                $this->instructions[$key] = '';
                $this->notes = ['count' => $result->factsSaved];

                return 'instructed';
            }

            $this->notes = ['facts' => implode('; ', $result->unmatched)];

            return $result->status;
        });
    }

    public function openFact(string $key): void
    {
        $report = $this->report();
        $this->factSection = $key;
        $this->factTask = '';
        $this->factType = 'other';
        $this->factSummary = '';
        $this->factDate = min($report->period_end->format('Y-m-d'), now($this->timezone())->format('Y-m-d'));
    }

    public function dismissFact(string $key): void
    {
        $this->factOffers = array_values(array_filter($this->factOffers, fn (string $k): bool => $k !== $key));

        if ($this->factSection === $key) {
            $this->factSection = null;
        }
    }

    public function saveFact(): void
    {
        $key = (string) $this->factSection;

        $this->guarded(function () use ($key): string {
            $type = ActivityType::tryFrom($this->factType) ?? ActivityType::Other;
            $result = app(ReportEditor::class)->saveFact($this->report(), (int) $this->factTask, $this->factDate, $this->factSummary, $type);

            if (! $result->ok()) {
                return 'invalid';
            }

            $this->dismissFact($key);

            return 'fact_saved';
        });
    }

    /**
     * Tasks of this report (for the "save as activity" form).
     *
     * @return array<int, string>
     */
    public function reportTasks(): array
    {
        $version = $this->loadedVersion();

        if ($version === null) {
            return [];
        }

        $data = app(ReportDataSelector::class)->select($this->report()->project, $this->report()->period_start->format('Y-m-d'), $this->report()->period_end->format('Y-m-d'), $this->timezone(), CarbonImmutable::instance($version->data_snapshot_at), $version->source_activity_ids);

        return array_map(fn (array $t): string => $t['title'], $data->tasks);
    }

    /** "Perbarui Draft" / "Buat Versi Baru": a new version from a fresh snapshot of the period. */
    public function regenerate(): void
    {
        $report = $this->report();
        $result = app(ReportRequests::class)->request($report->project, $report->period_start->format('Y-m-d'), $report->period_end->format('Y-m-d'), $report->language);

        $this->record = Report::query()->with(['project', 'currentVersion'])->findOrFail($report->id);
        $note = Notification::make()->title((string) __('ui.dashboard.reports.notices.'.$result['status']));
        ($result['status'] === ReportRequests::QUEUED ? $note->success() : $note->warning())->send();
    }

    /** "Abaikan": the report stays as approved and later changes are counted from now. */
    public function ignoreDrift(): void
    {
        $this->guarded(function (ReportWorkflow $workflow): string {
            $workflow->dismissDrift($this->report());
            $this->driftCount = 0;

            return 'drift_dismissed';
        });
    }

    public function approve(): void
    {
        $this->guarded(function (ReportWorkflow $workflow): string {
            $workflow->approve($this->report(), $this->loadedVersionId);

            return 'approved';
        });
    }

    public function cancelReport(): void
    {
        $this->guarded(function (ReportWorkflow $workflow): string {
            $workflow->cancel($this->report());

            return 'cancelled';
        });
    }

    protected function getHeaderActions(): array
    {
        $report = $this->report();

        return [
            Action::make('sendTelegram')
                ->label(__('ui.dashboard.reports.view.send_telegram'))
                ->color('gray')
                ->visible(fn (): bool => $this->loadedVersionId !== 0 && auth()->user() instanceof User && auth()->user()->telegram_user_id !== null)
                ->action(function (): void {
                    SendReportReview::dispatch($this->loadedVersionId, (int) auth()->id());
                    Notification::make()->title((string) __('ui.dashboard.reports.notices.sent_telegram'))->success()->send();
                }),
            ListReports::generateAction($report->project_id, $report->period_start->format('Y-m-d'), $report->period_end->format('Y-m-d'), $report->language->value, 'regenerate')->color('gray'),
        ];
    }

    public function isDirty(): bool
    {
        $version = $this->loadedVersion();

        if ($version === null) {
            return false;
        }

        foreach ((array) $version->content['sections'] as $section) {
            if (trim($this->sections[$section['key']] ?? '') !== trim((string) $section['markdown'])) {
                return true;
            }
        }

        return false;
    }

    public function previewHtml(): string
    {
        $version = $this->loadedVersion();

        return $version === null ? '' : app(ReportHtml::class)->render($version);
    }

    /**
     * Fresh signed links for the loaded version's files; empty until both exist.
     *
     * @return array<string, string> format => URL
     */
    public function downloads(): array
    {
        $user = auth()->user();
        $files = ReportFile::query()->where('report_version_id', $this->loadedVersionId)->get();

        if (! $user instanceof User || $files->count() < 2) {
            return [];
        }

        $links = [];

        foreach ($files as $file) {
            $links[$file->format->value] = (string) app(SignedDownload::class)->url($file->id, $user);
        }

        return $links;
    }

    /**
     * @return Collection<int, ReportVersion>
     */
    public function versions(): Collection
    {
        return ReportVersion::query()->where('report_id', $this->report()->id)->orderByDesc('version_no')->get();
    }

    /**
     * @return list<array{key: string, title: string, state: string, lines: list<array{type: string, text: string}>}>
     */
    public function diff(): array
    {
        if ($this->compareFrom === null || $this->compareTo === null) {
            return [];
        }

        $from = ReportVersion::query()->where('report_id', $this->report()->id)->find($this->compareFrom);
        $to = ReportVersion::query()->where('report_id', $this->report()->id)->find($this->compareTo);

        return $from === null || $to === null ? [] : app(ReportDiff::class)->compare($from, $to);
    }

    public function isGenerating(): bool
    {
        return $this->report()->status === ReportStatus::Generating;
    }

    public function isEditable(): bool
    {
        return ! in_array($this->report()->status, [ReportStatus::Generating, ReportStatus::Cancelled], true) && $this->loadedVersionId !== 0;
    }

    public function timezone(): string
    {
        return ReportResource::timezone();
    }

    private function loadVersion(?int $id): void
    {
        $this->loadedVersionId = $id ?? 0;
        $this->newerAvailable = false;
        $this->sections = [];

        $version = $this->loadedVersion();

        foreach ((array) ($version?->content['sections'] ?? []) as $section) {
            $this->sections[(string) $section['key']] = (string) $section['markdown'];
        }

        $versions = $this->versions();
        $this->compareTo = $version?->id;
        $this->compareFrom = $versions->firstWhere('version_no', ($version === null ? 1 : $version->version_no) - 1)?->id;
    }

    /**
     * @param  callable(ReportWorkflow): string  $step  returns the notice key
     */
    private function guarded(callable $step): void
    {
        try {
            $key = $step(app(ReportWorkflow::class));
        } catch (StaleReportException) {
            $key = 'stale';
        } catch (InvalidArgumentException) {
            $key = 'not_possible';
        }

        $this->record = Report::query()->with(['project', 'currentVersion'])->findOrFail($this->report()->id);
        $notification = Notification::make()->title((string) __('ui.dashboard.reports.notices.'.$key, $this->notes));
        $this->notes = [];
        (in_array($key, ['saved', 'approved', 'cancelled', 'instructed', 'fact_saved', 'drift_dismissed'], true) ? $notification->success() : $notification->warning())->send();
    }
}
