<?php

namespace App\Filament\Pages;

use App\Services\Ops\MetricsService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Computed;

/**
 * "Kesehatan" (PRD §73, §76, §78, §79): how the system is doing for this user, against the acceptance targets. Read-only.
 */
class Health extends Page
{
    protected string $view = 'filament.pages.health';

    protected static ?int $navigationSort = 20;

    /** Period in days: 7 or 30. */
    public int $days = 7;

    public static function getNavigationLabel(): string
    {
        return (string) __('ui.dashboard.health.nav');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedHeart;
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('ui.dashboard.health.title');
    }

    public function setDays(int $days): void
    {
        $this->days = $days === 30 ? 30 : 7;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function metrics(): array
    {
        $now = CarbonImmutable::now('UTC');

        return app(MetricsService::class)->summary($now->subDays($this->days), $now);
    }
}
