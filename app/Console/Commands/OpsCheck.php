<?php

namespace App\Console\Commands;

use App\Services\Ops\AlertDispatcher;
use App\Services\Ops\HealthChecks;
use Illuminate\Console\Command;

/**
 * Watches the system and tells the operator on Telegram when something needs attention (PRD §78, §84). Idempotent;
 * runs every five minutes.
 */
class OpsCheck extends Command
{
    protected $signature = 'ops:check';

    protected $description = 'Check disk, services, queues, backups and alert the operator on Telegram';

    public function handle(HealthChecks $checks, AlertDispatcher $alerts): int
    {
        $result = $alerts->reconcile($checks->conditions());

        $this->info('Raised: '.($result['raised'] === [] ? '-' : implode(', ', $result['raised'])).'. Recovered: '.($result['recovered'] === [] ? '-' : implode(', ', $result['recovered'])).'.');

        return self::SUCCESS;
    }
}
