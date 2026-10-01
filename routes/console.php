<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// PRD §25: the daily worklog reminder. Cheap when nothing is due; the dispatcher is idempotent.
Schedule::command('reminders:dispatch')->everyMinute()->withoutOverlapping(5);

// PRD §43: approved reports whose period changed afterwards become outdated, and the person is told once.
Schedule::command('reports:check-drift')->everyTenMinutes()->withoutOverlapping(10);
