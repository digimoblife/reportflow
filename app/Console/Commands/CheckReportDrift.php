<?php

namespace App\Console\Commands;

use App\Enums\ReportStatus;
use App\Jobs\SendReportOutdatedNotice;
use App\Models\Report;
use App\Models\User;
use App\Services\Report\ReportDrift;
use App\Services\Report\ReportWorkflow;
use App\Support\UserContext;
use Illuminate\Console\Command;

/**
 * Late entries (PRD §43): an approved report whose period changed afterwards is marked `outdated` and the person is told
 * once, with the choice "Buat Versi Baru" or "Abaikan". An outdated report whose changes disappeared (an undo) goes back to
 * approved quietly. Drafts are not touched here: the dashboard shows their drift live. Idempotent; runs every ten minutes.
 */
class CheckReportDrift extends Command
{
    protected $signature = 'reports:check-drift';

    protected $description = 'Mark approved reports whose period data changed as outdated and notify the user once';

    public function handle(UserContext $context, ReportDrift $drift, ReportWorkflow $workflow): int
    {
        $marked = 0;

        foreach ($context->runAsSystem(fn () => User::query()->whereNotNull('telegram_user_id')->orderBy('id')->get()) as $user) {
            $marked += $context->runAs($user->id, function () use ($user, $drift, $workflow): int {
                $count = 0;

                $reports = Report::query()->whereIn('status', [ReportStatus::Approved, ReportStatus::Outdated])->whereNotNull('current_version_id')->get();

                foreach ($reports as $report) {
                    $changes = $drift->count($report);

                    if ($report->status === ReportStatus::Approved && $changes > 0 && $workflow->markOutdated($report)) {
                        SendReportOutdatedNotice::dispatch($report->id, $user->id);
                        $count++;
                    } elseif ($report->status === ReportStatus::Outdated && $changes === 0) {
                        Report::query()->whereKey($report->id)->where('status', ReportStatus::Outdated)->update(['status' => ReportStatus::Approved]);
                    }
                }

                return $count;
            });
        }

        $this->info("Marked {$marked} report(s) outdated.");

        return self::SUCCESS;
    }
}
