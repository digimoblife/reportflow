<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Scrum\MorningScrumBriefService;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendMorningScrumBrief extends Command
{
    protected $signature = 'scrum:morning-brief {--user= : User ID to send brief to} {--force : Send regardless of schedule or time}';

    protected $description = 'Send morning scrum brief standup helper to Telegram (PRD Scrum §5)';

    public function handle(MorningScrumBriefService $service, UserContext $context): int
    {
        $userId = $this->option('user');
        $force = (bool) $this->option('force');

        if ($userId !== null) {
            $user = $context->runAsSystem(fn () => User::find((int) $userId));
            if (! $user) {
                $this->error("User {$userId} not found.");

                return self::FAILURE;
            }

            $sent = $context->runAs($user->id, fn () => $service->sendBriefForUser($user, CarbonImmutable::now('UTC'), force: true));
            $this->info("Sent {$sent} morning brief message(s) to user {$user->id}.");

            return self::SUCCESS;
        }

        $sent = $service->sendAllDue(now: CarbonImmutable::now('UTC'), force: $force);
        $this->info("Dispatched {$sent} morning brief message(s).");

        return self::SUCCESS;
    }
}
