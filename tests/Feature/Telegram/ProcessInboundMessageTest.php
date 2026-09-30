<?php

use App\Enums\InboundMessageStatus;
use App\Jobs\DeliverInboundConfirmation;
use App\Jobs\ProcessInboundMessage;
use App\Models\InboundMessage;
use App\Services\Ai\AiProviderException;
use App\Services\Telegram\TelegramApiException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeSecrets;
use Tests\Support\TelegramPayload;

/*
| These tests use the real `database` queue and the real `queue:work` command, one worker,
| so retries, backoff, releases and middleware behave like production.
*/

beforeEach(function () {
    config(['queue.default' => 'database']);
    Cache::flush();
    $this->user = registerTelegramUser(555001);
    $this->travelBase = Carbon::create(2026, 9, 30, 10, 0, 0, 'UTC');
    Carbon::setTestNow($this->travelBase);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Run one worker until no job is currently available (delayed jobs are not picked up). */
function runWorker(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--queue' => 'default', '--memory' => 4096]);
}

function advance(int $seconds): void
{
    Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
}

function pendingJobs(): int
{
    return DB::table('jobs')->count();
}

function send(string $text, int $messageId): void
{
    postTelegram(TelegramPayload::message($text, messageId: $messageId))->assertOk();
}

it('processes a message end to end: ⏳ becomes the confirmation on the same message', function () {
    send('Hari ini fix bug login 9Club, sudah selesai', 1);

    $ack = fakeTelegram()->sent[0];
    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Received);

    runWorker();

    $message = storedMessages()->sole();
    expect($message->status)->toBe(InboundMessageStatus::Processed)
        ->and($message->error)->toBeNull()
        ->and(fakeTelegram()->sent)->toHaveCount(1)
        ->and(fakeTelegram()->edits)->toHaveCount(1)
        ->and(fakeTelegram()->edits[0]['message_id'])->toBe($ack['message_id'])
        ->and(fakeTelegram()->edits[0]['chat_id'])->toBe(555001)
        ->and(isVariantOf(fakeTelegram()->edits[0]['text'], 'worklog.recorded_dummy'))->toBeTrue()
        ->and(fakeAi()->requests)->toHaveCount(1)
        ->and(fakeAi()->requests[0]->input)->toBe('Hari ini fix bug login 9Club, sudah selesai');
    expect(pendingJobs())->toBe(0);
});

it('sends the confirmation as a new message when the acknowledgement never went out', function () {
    fakeTelegram()->failNextSend(TelegramApiException::transport('sendMessage'));
    send('catatan tanpa ack', 2);

    runWorker();

    $message = storedMessages()->sole();
    expect($message->status)->toBe(InboundMessageStatus::Processed)
        ->and(fakeTelegram()->edits)->toBe([])
        ->and(fakeTelegram()->sent)->toHaveCount(1)
        ->and(isVariantOf(fakeTelegram()->sent[0]['text'], 'worklog.recorded_dummy'))->toBeTrue()
        ->and($message->reply_message_id)->toBe(fakeTelegram()->sent[0]['message_id']);
});

it('is idempotent: running the same job twice processes and confirms once', function () {
    send('sekali saja', 3);
    $message = storedMessages()->sole();

    runWorker();
    // A duplicate dispatch (webhook redelivery, manual re-queue) after the message was handled:
    ProcessInboundMessage::dispatch($message->id, $this->user->id);
    DeliverInboundConfirmation::dispatch($message->id, $this->user->id, DeliverInboundConfirmation::PROCESSED);
    runWorker();

    expect(fakeAi()->requests)->toHaveCount(1)
        ->and(fakeTelegram()->edits)->toHaveCount(1)
        ->and(fakeTelegram()->sent)->toHaveCount(1);
});

it('only claims a message that is still received', function () {
    send('sedang dikerjakan worker lain', 4);
    $message = storedMessages()->sole();
    asSystem(fn () => InboundMessage::query()->whereKey($message->id)->update(['status' => InboundMessageStatus::Processing]));

    runWorker(); // first attempt must not steal a message another worker is processing

    expect(fakeAi()->requests)->toBe([])
        ->and(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Processing);
});

it('retries a failing message, then marks it failed with a content-free error and tells the user', function () {
    // Even if a provider error message wrongly echoed a secret, the log processor removes it.
    fakeAi()->failWith(new AiProviderException('provider rejected key '.FakeSecrets::canary()));
    send('catatan penting yang tidak boleh hilang', 5);
    $logs = captureLogs();

    runWorker();  // attempt 1 fails, released with backoff 5s
    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Processing)
        ->and(pendingJobs())->toBe(1);

    advance(6);
    runWorker();  // attempt 2 fails, backoff 30s
    advance(31);
    runWorker();  // attempt 3 fails: maxExceptions reached -> failed()

    $message = storedMessages()->sole();
    expect($message->status)->toBe(InboundMessageStatus::Failed)
        ->and($message->error)->toBe('worklog_failed:AiProviderException')
        ->and($message->text)->toBe('catatan penting yang tidak boleh hilang') // raw input is preserved (PRD §58)
        ->and(fakeAi()->requests)->toHaveCount(3);

    // The ⏳ message was edited into the plain error text.
    expect(fakeTelegram()->edits)->toHaveCount(1)
        ->and(isVariantOf(fakeTelegram()->edits[0]['text'], 'worklog.failed'))->toBeTrue()
        ->and(pendingJobs())->toBe(0)
        ->and(loggedText($logs))->not->toContain(FakeSecrets::canary())->not->toContain('catatan penting')
        ->and(loggedText($logs))->toContain('inbound.processing_failed');
});

it('recovers when a retry succeeds and does not send the error message', function () {
    fakeAi()->failWith(new AiProviderException('sementara'), times: 1);
    send('coba lagi', 6);

    runWorker();
    advance(6);
    runWorker();

    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Processed)
        ->and(fakeTelegram()->edits)->toHaveCount(1)
        ->and(isVariantOf(fakeTelegram()->edits[0]['text'], 'worklog.recorded_dummy'))->toBeTrue();
});

it('never turns a processed message into a failed one when Telegram is down', function () {
    send('telegram sedang bermasalah', 7);
    fakeTelegram()->failNextEdit(TelegramApiException::fromResponse('editMessageText', 502, 'Bad Gateway'));

    runWorker();

    // Processed once; the confirmation is waiting to be retried, processing is not repeated.
    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Processed)
        ->and(fakeTelegram()->edits)->toBe([])
        ->and(pendingJobs())->toBe(1);

    advance(15);
    runWorker();

    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Processed)
        ->and(fakeTelegram()->edits)->toHaveCount(1)
        ->and(fakeAi()->requests)->toHaveCount(1)
        ->and(pendingJobs())->toBe(0);
});

it('waits for retry_after when Telegram rate limits the confirmation', function () {
    send('kena rate limit', 8);
    fakeTelegram()->failNextEdit(TelegramApiException::fromResponse('editMessageText', 429, 'Too Many Requests', 40));

    runWorker();
    advance(20);
    runWorker(); // too early: still delayed
    expect(fakeTelegram()->edits)->toBe([]);

    advance(25);
    runWorker();

    expect(fakeTelegram()->edits)->toHaveCount(1)
        ->and(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Processed);
});

it('does not retry permanent Telegram errors and keeps the message processed', function (int $status, string $description) {
    send('pengguna memblokir bot', 9);
    fakeTelegram()
        ->failNextEdit(TelegramApiException::fromResponse('editMessageText', $status, $description))
        ->failNextSend(TelegramApiException::fromResponse('sendMessage', $status, $description));
    // The acknowledgement was sent before the failures were queued, so the edit fails first and
    // the fallback send fails second: nothing more can be done.
    $logs = captureLogs();

    runWorker();

    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Processed)
        ->and(pendingJobs())->toBe(0)
        ->and(collect($logs->getRecords())->pluck('message')->all())->toContain('inbound.confirmation_undeliverable');
})->with([[403, 'Forbidden: bot was blocked by the user'], [400, 'Bad Request: chat not found']]);

it('falls back to a new message when the acknowledgement can no longer be edited', function () {
    send('ack sudah dihapus', 10);
    fakeTelegram()->failNextEdit(TelegramApiException::fromResponse('editMessageText', 400, 'Bad Request: message to edit not found'));

    runWorker();

    $message = storedMessages()->sole();
    expect(fakeTelegram()->sent)->toHaveCount(2)
        ->and(isVariantOf(fakeTelegram()->sent[1]['text'], 'worklog.recorded_dummy'))->toBeTrue()
        ->and($message->reply_message_id)->toBe(fakeTelegram()->sent[1]['message_id']);
});

it('treats "message is not modified" as delivered', function () {
    send('sudah terkirim', 11);
    fakeTelegram()->failNextEdit(TelegramApiException::fromResponse('editMessageText', 400, 'Bad Request: message is not modified'));

    runWorker();

    expect(fakeTelegram()->sent)->toHaveCount(1)->and(pendingJobs())->toBe(0);
});

it('processes ten consecutive messages of one user with one worker, none failed', function () {
    foreach (range(1, 10) as $i) {
        send("catatan nomor $i", 100 + $i);
    }
    expect(pendingJobs())->toBe(10);

    runWorker();

    $rows = storedMessages();
    expect($rows)->toHaveCount(10)
        ->and($rows->pluck('status')->unique()->all())->toBe([InboundMessageStatus::Processed])
        ->and($rows->whereNotNull('error'))->toHaveCount(0)
        ->and(fakeTelegram()->edits)->toHaveCount(10)
        ->and(fakeTelegram()->sent)->toHaveCount(10)
        ->and(pendingJobs())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('releases (does not fail) a job that meets its user lock, and finishes it later', function () {
    send('menunggu giliran', 12);
    $message = storedMessages()->sole();
    $job = new ProcessInboundMessage($message->id, $this->user->id);
    $lockKey = (new WithoutOverlapping('inbound-user:'.$this->user->id))->getLockKey($job);
    $lock = Cache::lock($lockKey, 120);
    expect($lock->get())->toBeTrue();

    runWorker();

    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Received)
        ->and(pendingJobs())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    $lock->release();
    advance(6);
    runWorker();

    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Processed)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('does not lock different users out of each other', function () {
    $other = registerTelegramUser(555002);
    send('milik pertama', 13);
    postTelegram(TelegramPayload::message('milik kedua', from: 555002, messageId: 13))->assertOk();

    $lock = Cache::lock((new WithoutOverlapping('inbound-user:'.$this->user->id))->getLockKey(new ProcessInboundMessage(1, $this->user->id)), 120);
    $lock->get();

    runWorker();

    $byUser = storedMessages()->groupBy('user_id');
    expect($byUser[$other->id]->sole()->status)->toBe(InboundMessageStatus::Processed)
        ->and($byUser[$this->user->id]->sole()->status)->toBe(InboundMessageStatus::Received);
});

it('carries ids only in the queued payload, never message text', function () {
    $secretLooking = 'catatan-unik-'.uniqid();
    send($secretLooking, 14);

    $payload = DB::table('jobs')->pluck('payload')->implode(' ');

    expect($payload)->not->toContain($secretLooking)->and($payload)->toContain('ProcessInboundMessage');
});

it('serves a dashboard-sourced message without touching Telegram', function () {
    $row = asSystem(fn () => InboundMessage::query()->create([
        'user_id' => $this->user->id,
        'source' => 'dashboard',
        'idempotency_key' => 'dashboard:'.Str::uuid(),
        'text' => 'dari dashboard',
        'received_at' => now(),
    ]));

    ProcessInboundMessage::dispatch($row->id, $this->user->id);
    runWorker();

    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Processed)
        ->and(fakeTelegram()->sent)->toBe([])
        ->and(fakeTelegram()->edits)->toBe([]);
});
