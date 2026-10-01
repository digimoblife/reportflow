<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Enums\ReportStatus;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\ReportVersion;
use App\Models\User;
use App\Services\Report\ReportDiff;
use App\Services\Report\ReportHtml;
use App\Services\Report\ReportWorkflow;
use App\Services\Report\SignedDownload;
use App\Services\Report\StaleReportException;
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

    public bool $newerAvailable = false;

    public ?int $compareFrom = null;

    public ?int $compareTo = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->loadVersion($this->report()->current_version_id);
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
            $version = $workflow->saveEdit($this->report(), $this->loadedVersionId, $this->sections);

            if ($version === null) {
                return 'unchanged';
            }

            $this->loadVersion($version->id);

            return 'saved';
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
        $notification = Notification::make()->title((string) __('ui.dashboard.reports.notices.'.$key));
        (in_array($key, ['stale', 'not_possible'], true) ? $notification->warning() : $notification->success())->send();
    }
}
