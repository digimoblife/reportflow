<?php

namespace App\Filament\Pages;

use App\Enums\Language;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use App\Services\Worklog\ProjectService;
use BackedEnum;
use DateTimeZone;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Settings (PRD §22 page 5): account preferences and projects. The reminder switch is stored here; the reminders
 * themselves arrive with M6. Projects are changed through ProjectService, the same code the bot uses.
 */
class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static ?int $navigationSort = 10;

    public string $language = 'id';

    public string $timezone = 'Asia/Jakarta';

    /** @var array<int, string> */
    public array $workdays = [];

    public bool $remindersEnabled = false;

    public string $newProject = '';

    /** @var array<int, string> project id => name being edited */
    public array $names = [];

    /** @var array<int, string> project id => aliases, comma separated */
    public array $aliases = [];

    public ?string $notice = null;

    public string $noticeKind = 'info';

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public static function getNavigationLabel(): string
    {
        return (string) __('ui.dashboard.settings.nav');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedCog6Tooth;
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('ui.dashboard.settings.title');
    }

    public function mount(): void
    {
        $user = $this->user();
        $this->language = $user->default_language->value;
        $this->timezone = $user->timezone;
        $this->workdays = collect((array) $user->workdays)->map(fn ($day): string => (string) $day)->values()->all();
        $this->remindersEnabled = (bool) $user->reminders_enabled;
        $this->loadProjects();
    }

    public function saveProfile(): void
    {
        $validated = validator(
            ['language' => $this->language, 'timezone' => $this->timezone, 'workdays' => $this->workdays],
            [
                'language' => ['required', Rule::in(array_map(fn (Language $l): string => $l->value, Language::cases()))],
                'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
                'workdays' => ['array', 'min:1'],
                'workdays.*' => ['string', Rule::in(self::DAYS)],
            ],
        );

        if ($validated->fails()) {
            $this->flash('invalid', false);

            return;
        }

        $this->user()->update([
            'default_language' => Language::from($this->language),
            'timezone' => $this->timezone,
            'workdays' => array_values(array_intersect(self::DAYS, $this->workdays)),
            'reminders_enabled' => $this->remindersEnabled,
        ]);

        app()->setLocale($this->language);
        $this->flash('saved', true);
    }

    public function addProject(): void
    {
        $service = app(ProjectService::class);
        $name = $service->normalizeName($this->newProject);

        if ($name === null) {
            $this->flash('project_exists', false);

            return;
        }

        $service->findOrCreate($name);
        $this->newProject = '';
        $this->loadProjects();
        $this->flash('project_added', true);
    }

    public function saveProject(int $id): void
    {
        $project = Project::query()->findOrFail($id);
        $service = app(ProjectService::class);

        if (($this->names[$id] ?? $project->name) !== $project->name && ! $service->rename($project, (string) $this->names[$id])) {
            $this->flash('project_exists', false);

            return;
        }

        $service->setAliases($project, explode(',', $this->aliases[$id] ?? ''));
        $this->loadProjects();
        $this->flash('project_saved', true);
    }

    public function setProjectActive(int $id, bool $active): void
    {
        app(ProjectService::class)->setActive(Project::query()->findOrFail($id), $active);
        $this->loadProjects();
        $this->flash('project_saved', true);
    }

    /**
     * @return list<array{id: int, name: string, active: bool}>
     */
    #[Computed]
    public function projects(): array
    {
        return array_values(Project::query()->orderBy('status')->orderBy('name')->get()->map(fn (Project $p): array => [
            'id' => $p->id, 'name' => $p->name, 'active' => $p->status === ProjectStatus::Active,
        ])->all());
    }

    /**
     * @return list<string>
     */
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    private function loadProjects(): void
    {
        foreach (Project::query()->get() as $project) {
            $this->names[$project->id] = $project->name;
            $this->aliases[$project->id] = implode(', ', array_filter((array) $project->aliases, 'is_string'));
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function flash(string $key, bool $success): void
    {
        $this->notice = (string) __('ui.dashboard.settings.notices.'.$key);
        $this->noticeKind = $success ? 'success' : 'warning';
    }
}
