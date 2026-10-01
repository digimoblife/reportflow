<?php

namespace App\Jobs;

use App\Enums\ReportFileFormat;
use App\Jobs\Middleware\WithUserContext;
use App\Models\ReportFile;
use App\Models\ReportVersion;
use App\Models\User;
use App\Services\Ops\OpsEvents;
use App\Services\Telegram\BotMessages;
use App\Services\Telegram\ReportReviewComposer;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramMessenger;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Delivers a report to the user's Telegram (PRD §42, §65): the review message with its buttons plus the PDF (`review`),
 * or, once approved, the PDF and the Markdown file (`files`). The files are made in the background, so the job waits for
 * them (up to `WAIT_SECONDS`) and then sends what exists; the message never waits forever. Idempotent per dispatch:
 * a retry does not repeat a step that already went out (cache markers keyed by this dispatch's token). Ids only.
 */
class SendReportReview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const REVIEW = 'review';

    public const FILES = 'files';

    public const WAIT_SECONDS = 180;

    public readonly string $token;

    public readonly int $deadline;

    public function __construct(
        public readonly int $reportVersionId,
        public readonly int $userId,
        public readonly string $mode = self::REVIEW,
    ) {
        $this->onQueue('reports');
        $this->token = Str::random(16);
        $this->deadline = Carbon::now()->getTimestamp() + self::WAIT_SECONDS;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new WithUserContext($this->userId)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestampUTC($this->deadline + 600);
    }

    public function handle(TelegramMessenger $messenger, BotMessages $messages, ReportReviewComposer $composer): void
    {
        $version = ReportVersion::query()->find($this->reportVersionId);
        $user = User::query()->find($this->userId);

        if ($version === null || $user === null || $user->telegram_user_id === null) {
            return;
        }

        $files = ReportFile::query()->where('report_version_id', $version->id)->get()->keyBy(fn (ReportFile $f): string => $f->format->value);
        $ready = $files->has('pdf') && $files->has('md');

        if (! $ready && Carbon::now()->getTimestamp() < $this->deadline) {
            $this->release(10);

            return;
        }

        $chatId = $user->telegram_user_id;
        $report = $version->report;
        $language = $user->default_language;

        try {
            if ($this->mode === self::REVIEW && $this->once('msg')) {
                $view = $composer->review($report, $version, $language, $user->timezone);
                $messenger->send($chatId, $view['text'].($ready ? '' : "\n\n".$messages->get('report.files_pending', $language)), null, $view['keyboard']);
                $this->done('msg');
            }

            foreach ($this->documents($files) as $format => $file) {
                if ($this->once($format)) {
                    $messenger->sendDocument($chatId, $this->filename($version, $format), (string) Storage::disk('reports')->get($file->file_path), isset($version->content['title']) ? (string) $version->content['title'] : null);
                    $this->done($format);
                }
            }
        } catch (TelegramApiException $e) {
            if ($e->isRetryable()) {
                $this->release(max($e->retryAfter ?? 0, 15));

                return;
            }

            OpsEvents::record(OpsEvents::TELEGRAM_FAILED, ['method' => $e->apiMethod, 'status' => $e->httpStatus]);
            Log::warning('report.review_undeliverable', ['report_version_id' => $version->id, 'method' => $e->apiMethod, 'status' => $e->httpStatus]);
        }
    }

    /**
     * Review sends the PDF; the approved delivery sends the PDF and the Markdown.
     *
     * @param  Collection<string, ReportFile>  $files
     * @return array<string, ReportFile>
     */
    private function documents($files): array
    {
        $wanted = $this->mode === self::FILES ? [ReportFileFormat::Pdf->value, ReportFileFormat::Md->value] : [ReportFileFormat::Pdf->value];

        return array_filter(array_combine($wanted, array_map(fn (string $f): ?ReportFile => $files->get($f), $wanted)), fn ($f): bool => $f !== null);
    }

    private function filename(ReportVersion $version, string $format): string
    {
        return Str::slug((string) ($version->content['title'] ?? 'report')).'-v'.$version->version_no.'.'.$format;
    }

    private function once(string $step): bool
    {
        return ! Cache::has($this->marker($step));
    }

    private function done(string $step): void
    {
        Cache::put($this->marker($step), true, 3600);
    }

    private function marker(string $step): string
    {
        return "report:send:{$this->token}:{$step}";
    }
}
