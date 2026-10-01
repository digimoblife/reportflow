<?php

use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Jobs\ProcessInboundMessage;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Services\Telegram\OnboardingState;
use App\Services\Telegram\TelegramApiException;
use App\Support\UserContext;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSecrets;
use Tests\Support\TelegramPayload;

const USER_TG = 555001;

describe('worklog messages (queue faked)', function () {
    beforeEach(function () {
        Queue::fake();
        $this->user = registerTelegramUser(USER_TG);
    });

    it('stores the redacted message, acknowledges it and dispatches processing', function () {
        postTelegram(TelegramPayload::message('Hari ini fix bug login 9Club, sudah selesai', messageId: 10))->assertOk();

        $message = storedMessages()->sole();

        expect($message->status)->toBe(InboundMessageStatus::Received)
            ->and($message->idempotency_key)->toBe('telegram:'.USER_TG.':10')
            ->and($message->text)->toBe('Hari ini fix bug login 9Club, sudah selesai')
            ->and($message->user_id)->toBe($this->user->id)
            ->and($message->telegram_chat_id)->toBe(USER_TG)
            ->and($message->telegram_message_id)->toBe(10)
            ->and($message->source->value)->toBe('telegram')
            ->and($message->attachments)->toBe([])
            ->and($message->edited_at)->toBeNull();

        $sent = fakeTelegram()->sent;
        expect($sent)->toHaveCount(1)
            ->and(isVariantOf($sent[0]['text'], 'worklog.ack'))->toBeTrue()
            ->and($sent[0]['reply_to'])->toBe(10)
            ->and($message->reply_message_id)->toBe($sent[0]['message_id']);

        Queue::assertPushed(ProcessInboundMessage::class, fn ($job) => $job->inboundMessageId === $message->id && $job->userId === $this->user->id && $job->queue === 'default');
        Queue::assertPushed(ProcessInboundMessage::class, 1);
    });

    it('acknowledges in English when the message is English', function () {
        postTelegram(TelegramPayload::message('Fixed the login bug today and we are still waiting for the client'))->assertOk();

        expect(isVariantOf(fakeTelegram()->sent[0]['text'], 'worklog.ack', 'en'))->toBeTrue();
    });

    it('falls back to the user default language when the message is ambiguous', function () {
        $this->user->update(['default_language' => Language::English]);

        postTelegram(TelegramPayload::message('deploy 9Club'))->assertOk();

        expect(isVariantOf(fakeTelegram()->sent[0]['text'], 'worklog.ack', 'en'))->toBeTrue();
    });

    it('treats a duplicate delivery as success without a second row, acknowledgement or job', function () {
        $payload = TelegramPayload::message('catatan pertama', messageId: 21);

        postTelegram($payload)->assertOk();
        postTelegram($payload)->assertOk();

        expect(storedMessages())->toHaveCount(1)
            ->and(fakeTelegram()->sent)->toHaveCount(1);
        // The row is still `received` (jobs are faked), so the second delivery re-dispatches once. That is safe
        // because the job claims the row atomically (see ProcessInboundMessageTest).
        Queue::assertPushed(ProcessInboundMessage::class, 2);
    });

    it('does not re-dispatch a duplicate once the message has been handled', function () {
        $payload = TelegramPayload::message('catatan', messageId: 22);
        postTelegram($payload)->assertOk();

        asSystem(fn () => InboundMessage::query()->update(['status' => InboundMessageStatus::Processed]));

        postTelegram($payload)->assertOk();

        Queue::assertPushed(ProcessInboundMessage::class, 1);
        expect(storedMessages())->toHaveCount(1)->and(fakeTelegram()->sent)->toHaveCount(1);
    });

    it('survives duplicates inside the surrounding transaction (no unique-violation exception path)', function () {
        $payload = TelegramPayload::message('sekali lagi', messageId: 23);

        foreach (range(1, 5) as $i) {
            postTelegram($payload)->assertOk();
        }

        // If a unique violation had aborted the transaction, this query would fail with SQLSTATE 25P02.
        expect(storedMessages())->toHaveCount(1);
    });

    it('stores different messages of the same user as separate rows in arrival order', function () {
        foreach ([31, 32, 33] as $id) {
            postTelegram(TelegramPayload::message("catatan $id", messageId: $id))->assertOk();
        }

        expect(storedMessages()->pluck('telegram_message_id')->all())->toBe([31, 32, 33]);
        Queue::assertPushed(ProcessInboundMessage::class, 3);
    });

    it('keeps the message and dispatches even when the acknowledgement cannot be sent', function () {
        fakeTelegram()->failNextSend(TelegramApiException::transport('sendMessage'));

        postTelegram(TelegramPayload::message('tetap tersimpan'))->assertOk();

        $message = storedMessages()->sole();
        expect($message->reply_message_id)->toBeNull()
            ->and($message->text)->toBe('tetap tersimpan');
        Queue::assertPushed(ProcessInboundMessage::class, 1);
    });

    it('never stores or forwards a secret: text is redacted, the user is told, values are not kept', function () {
        $secret = FakeSecrets::canary();
        $password = FakeSecrets::password();

        postTelegram(TelegramPayload::message("deploy selesai pakai key $secret dan password: $password"))->assertOk();

        $message = storedMessages()->sole();
        expect($message->text)->toBe('deploy selesai pakai key [REDACTED_SECRET] dan password: [REDACTED_SECRET]')
            ->and($message->text)->not->toContain($secret)->not->toContain($password);

        $texts = fakeTelegram()->allTexts();
        expect($texts)->toHaveCount(2)
            ->and(isVariantOf($texts[1], 'security.credential_detected', 'id', ['count' => 2]))->toBeTrue()
            ->and(implode(' ', $texts))->not->toContain($secret);
    });

    it('stores a message that consists only of a secret as the placeholder', function () {
        postTelegram(TelegramPayload::message(FakeSecrets::githubToken()))->assertOk();

        expect(storedMessages()->sole()->text)->toBe('[REDACTED_SECRET]');
        Queue::assertPushed(ProcessInboundMessage::class, 1);
    });

    it('does not store or process a message that could not be checked safely', function () {
        config(['redaction.max_input_length' => 20]);

        postTelegram(TelegramPayload::message(str_repeat('a', 21)))->assertOk();

        expect(storedMessages())->toHaveCount(0);
        Queue::assertNothingPushed();
        $texts = fakeTelegram()->allTexts();
        expect($texts)->toHaveCount(1)
            ->and(isVariantOf($texts[0], 'security.redaction_error'))->toBeTrue()
            // a processing error, not a "credential detected" claim
            ->and(isVariantOf($texts[0], 'security.credential_detected', 'id', ['count' => 1]))->toBeFalse();
    });

    it('applies custom per-user redaction patterns', function () {
        config(['redaction.user_patterns' => [$this->user->id => ['~\bACME-[A-Z0-9]{8}\b~u']]]);

        postTelegram(TelegramPayload::message('kode klien ACME-AB12CD34 dipakai'))->assertOk();

        expect(storedMessages()->sole()->text)->toBe('kode klien [REDACTED_SECRET] dipakai');
    });

    it('stores long messages up to the Telegram limit', function () {
        $text = str_repeat('kerja ', 679).'kerja'; // ~4080 chars (Laravel trims surrounding whitespace of request input)

        postTelegram(TelegramPayload::message($text))->assertOk();

        expect(mb_strlen(storedMessages()->sole()->text))->toBe(mb_strlen($text));
    });

    it('processes a photo caption as text and records only the attachment type', function () {
        postTelegram(TelegramPayload::message(null, extra: [
            'caption' => 'screenshot error login',
            'photo' => [['file_id' => 'AgAD-private-file-id', 'width' => 90, 'height' => 90]],
        ]))->assertOk();

        $message = storedMessages()->sole();
        expect($message->text)->toBe('screenshot error login')
            ->and($message->attachments)->toBe([['type' => 'photo']])
            ->and(json_encode($message->attachments))->not->toContain('file-id');

        $texts = fakeTelegram()->allTexts();
        expect($texts)->toHaveCount(2)
            ->and(isVariantOf($texts[1], 'worklog.attachment_ignored'))->toBeTrue();
        Queue::assertPushed(ProcessInboundMessage::class, 1);
    });

    it('answers media-only messages with a not-supported notice and stores nothing', function (array $extra, string $key) {
        postTelegram(TelegramPayload::message(null, extra: $extra))->assertOk();

        expect(storedMessages())->toHaveCount(0)
            ->and(fakeTelegram()->sent)->toHaveCount(1)
            ->and(isVariantOf(fakeTelegram()->sent[0]['text'], $key))->toBeTrue();
        Queue::assertNothingPushed();
    })->with([
        'voice' => [['voice' => ['file_id' => 'x', 'duration' => 3]], 'unsupported.voice'],
        'audio' => [['audio' => ['file_id' => 'x']], 'unsupported.voice'],
        'photo' => [['photo' => [['file_id' => 'x']]], 'unsupported.image'],
        'sticker' => [['sticker' => ['file_id' => 'x']], 'unsupported.image'],
        'document' => [['document' => ['file_id' => 'x']], 'unsupported.other'],
        'contact' => [['contact' => ['phone_number' => '1']], 'unsupported.other'],
    ]);

    it('does not answer a media-only message twice when Telegram re-delivers it', function () {
        $payload = TelegramPayload::message(null, messageId: 41, extra: ['voice' => ['file_id' => 'x']]);

        postTelegram($payload)->assertOk();
        postTelegram($payload)->assertOk();

        expect(fakeTelegram()->sent)->toHaveCount(1);
    });

    it('keeps rows of different users apart', function () {
        $other = registerTelegramUser(555002);

        postTelegram(TelegramPayload::message('catatan A', from: USER_TG, messageId: 1))->assertOk();
        postTelegram(TelegramPayload::message('catatan B', from: 555002, messageId: 1))->assertOk();

        $rows = storedMessages();
        expect($rows)->toHaveCount(2)
            ->and($rows->firstWhere('telegram_chat_id', USER_TG)->user_id)->toBe($this->user->id)
            ->and($rows->firstWhere('telegram_chat_id', 555002)->user_id)->toBe($other->id);
        // No context leaks out of the request.
        expect(app(UserContext::class)->userId())->toBeNull();
    });
});

describe('edited messages (queue faked)', function () {
    beforeEach(function () {
        Queue::fake();
        registerTelegramUser(USER_TG);
        postTelegram(TelegramPayload::message('versi awal', messageId: 50))->assertOk();
        $this->sentBefore = count(fakeTelegram()->sent);
    });

    it('updates the stored text of a message that is still waiting, quietly', function () {
        postTelegram(TelegramPayload::edited('versi kedua', messageId: 50))->assertOk();

        $message = storedMessages()->sole();
        expect($message->text)->toBe('versi kedua')
            ->and($message->edited_at)->not->toBeNull()
            ->and($message->status)->toBe(InboundMessageStatus::Received)
            ->and(fakeTelegram()->sent)->toHaveCount($this->sentBefore);
        Queue::assertPushed(ProcessInboundMessage::class, 1);
    });

    it('updates quietly while the message is being processed', function () {
        asSystem(fn () => InboundMessage::query()->update(['status' => InboundMessageStatus::Processing]));

        postTelegram(TelegramPayload::edited('sedang diproses', messageId: 50))->assertOk();

        expect(storedMessages()->sole()->text)->toBe('sedang diproses')
            ->and(fakeTelegram()->sent)->toHaveCount($this->sentBefore);
    });

    it('updates a finished message and tells the user once that earlier notes did not change', function (InboundMessageStatus $status) {
        asSystem(fn () => InboundMessage::query()->update(['status' => $status]));
        $edit = TelegramPayload::edited('setelah selesai', messageId: 50, editDate: 1_780_000_200);

        postTelegram($edit)->assertOk();
        postTelegram($edit)->assertOk(); // Telegram re-delivers the same edit

        $sent = fakeTelegram()->sent;
        expect(storedMessages()->sole()->text)->toBe('setelah selesai')
            ->and($sent)->toHaveCount($this->sentBefore + 1)
            ->and(isVariantOf(end($sent)['text'], 'worklog.edit_saved_notice'))->toBeTrue();

        // A later, different edit is a new event and is announced again.
        postTelegram(TelegramPayload::edited('lagi', messageId: 50, editDate: 1_780_000_300))->assertOk();
        expect(fakeTelegram()->sent)->toHaveCount($this->sentBefore + 2);
        Queue::assertPushed(ProcessInboundMessage::class, 1); // never re-processed automatically
    })->with([InboundMessageStatus::Processed, InboundMessageStatus::Failed, InboundMessageStatus::NeedsClarification]);

    it('redacts edits and warns about credentials found in them', function () {
        $secret = FakeSecrets::openAiKey();

        postTelegram(TelegramPayload::edited("versi dengan $secret", messageId: 50))->assertOk();

        expect(storedMessages()->sole()->text)->toBe('versi dengan [REDACTED_SECRET]');
        $sent = fakeTelegram()->sent;
        expect(isVariantOf(end($sent)['text'], 'security.credential_detected', 'id', ['count' => 1]))->toBeTrue();
    });

    it('treats an edit of an unknown message as a new message', function () {
        postTelegram(TelegramPayload::edited('pesan lama yang tidak kami simpan', messageId: 999))->assertOk();

        $rows = storedMessages();
        expect($rows)->toHaveCount(2)
            ->and($rows->last()->telegram_message_id)->toBe(999)
            ->and($rows->last()->edited_at)->not->toBeNull();
        Queue::assertPushed(ProcessInboundMessage::class, 2);
    });
});

describe('commands and onboarding (queue faked)', function () {
    beforeEach(function () {
        Queue::fake();
        $this->user = registerTelegramUser(USER_TG);
    });

    it('greets on /start and asks for the first project name', function () {
        postTelegram(TelegramPayload::message('/start'))->assertOk();

        expect(storedMessages())->toHaveCount(0)
            ->and(fakeTelegram()->sent)->toHaveCount(1)
            ->and(isVariantOf(fakeTelegram()->sent[0]['text'], 'onboarding.welcome'))->toBeTrue();
        Queue::assertNothingPushed();
    });

    it('creates the first project from the next plain message', function () {
        postTelegram(TelegramPayload::message('/start', messageId: 1))->assertOk();
        postTelegram(TelegramPayload::message('  9Club   Portal ', messageId: 2))->assertOk();

        $project = asUser($this->user->id, fn () => Project::query()->sole());
        $sent = fakeTelegram()->sent;

        expect($project->name)->toBe('9Club Portal')
            ->and($project->slug)->toBe('9club-portal')
            ->and($project->default_language)->toBeNull()
            ->and(isVariantOf(end($sent)['text'], 'onboarding.project_created', 'id', ['project' => '9Club Portal']))->toBeTrue()
            ->and(storedMessages())->toHaveCount(0);

        // State is consumed: the next message is an ordinary worklog note.
        postTelegram(TelegramPayload::message('fix bug login', messageId: 3))->assertOk();
        expect(storedMessages())->toHaveCount(1)
            ->and(asUser($this->user->id, fn () => Project::query()->count()))->toBe(1);
    });

    it('reuses an existing project of the same name', function () {
        asUser($this->user->id, fn () => Project::factory()->create(['name' => '9Club', 'slug' => '9club']));
        // The user has a project, so /start is not onboarding; put the state there by hand.
        app(OnboardingState::class)->awaitProjectName($this->user->id);

        postTelegram(TelegramPayload::message('9club'))->assertOk();

        expect(asUser($this->user->id, fn () => Project::query()->count()))->toBe(1)
            ->and(isVariantOf(fakeTelegram()->sent[0]['text'], 'onboarding.project_exists', 'id', ['project' => '9Club']))->toBeTrue();
    });

    it('says the setup is done when /start is sent to a user that has projects', function () {
        asUser($this->user->id, fn () => Project::factory()->count(2)->create());

        postTelegram(TelegramPayload::message('/start'))->assertOk();

        expect(isVariantOf(fakeTelegram()->sent[0]['text'], 'onboarding.already_set_up', 'id', ['count' => 2]))->toBeTrue();
    });

    it('rejects unusable project names and keeps waiting', function (string $name) {
        postTelegram(TelegramPayload::message('/start', messageId: 1))->assertOk();
        postTelegram(TelegramPayload::message($name, messageId: 2))->assertOk();

        $sent = fakeTelegram()->sent;
        expect(isVariantOf(end($sent)['text'], 'onboarding.name_invalid'))->toBeTrue()
            ->and(asUser($this->user->id, fn () => Project::query()->count()))->toBe(0);

        postTelegram(TelegramPayload::message('Proyek Baik', messageId: 3))->assertOk();
        expect(asUser($this->user->id, fn () => Project::query()->count()))->toBe(1);
    })->with(['too long' => str_repeat('a', 81), 'only symbols' => '!!! ???']);

    it('does not turn a name containing a secret into a project', function () {
        postTelegram(TelegramPayload::message('/start', messageId: 1))->assertOk();
        postTelegram(TelegramPayload::message('Proyek '.FakeSecrets::githubToken(), messageId: 2))->assertOk();

        $sent = fakeTelegram()->sent;
        expect(isVariantOf(end($sent)['text'], 'security.credential_detected', 'id', ['count' => 1]))->toBeTrue()
            ->and(asUser($this->user->id, fn () => Project::query()->count()))->toBe(0)
            ->and(storedMessages())->toHaveCount(0);
    });

    it('answers /help', function () {
        postTelegram(TelegramPayload::message('/help'))->assertOk();

        expect(isVariantOf(fakeTelegram()->sent[0]['text'], 'help.guide'))->toBeTrue();
    });

    it('handles the command with a bot suffix and case differences', function () {
        postTelegram(TelegramPayload::message('/HELP@PakCarikk_bot'))->assertOk();

        expect(isVariantOf(fakeTelegram()->sent[0]['text'], 'help.guide'))->toBeTrue();
    });

    it('says commands from PRD §20 that are not built yet are not available', function (string $command) {
        postTelegram(TelegramPayload::message("/$command extra args"))->assertOk();

        expect(isVariantOf(fakeTelegram()->sent[0]['text'], 'commands.unavailable', 'id', ['command' => "/$command"]))->toBeTrue()
            ->and(storedMessages())->toHaveCount(0);
        Queue::assertNothingPushed();
    })->with(['generate', 'settings']);

    it('does not know /update or other unknown commands', function (string $command) {
        postTelegram(TelegramPayload::message("/$command"))->assertOk();

        expect(isVariantOf(fakeTelegram()->sent[0]['text'], 'commands.unknown', 'id', ['command' => "/$command"]))->toBeTrue();
    })->with(['update', 'foobar']);

    it('answers a re-delivered command only once', function () {
        $payload = TelegramPayload::message('/help', messageId: 60);

        postTelegram($payload)->assertOk();
        postTelegram($payload)->assertOk();

        expect(fakeTelegram()->sent)->toHaveCount(1);
    });

    it('answers commands in the user default language', function () {
        $this->user->update(['default_language' => Language::English]);

        postTelegram(TelegramPayload::message('/help'))->assertOk();

        expect(isVariantOf(fakeTelegram()->sent[0]['text'], 'help.guide', 'en'))->toBeTrue();
    });
});
