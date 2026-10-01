<?php

namespace App\Console\Commands;

use App\Services\Reminder\ReminderDispatcher;
use Illuminate\Console\Command;

class DispatchReminders extends Command
{
    protected $signature = 'reminders:dispatch';

    protected $description = 'Create due reminders and queue the ones that may be sent (runs every minute)';

    public function handle(ReminderDispatcher $dispatcher): int
    {
        $queued = $dispatcher->run();

        $this->info("Queued {$queued} reminder(s).");

        return self::SUCCESS;
    }
}
