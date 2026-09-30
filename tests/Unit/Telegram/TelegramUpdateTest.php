<?php

use App\Services\Telegram\TelegramText;
use App\Services\Telegram\TelegramUpdate;
use Tests\Support\TelegramPayload;

it('parses a private text message', function () {
    $update = TelegramUpdate::fromArray(TelegramPayload::message('halo', from: 42, messageId: 7));

    expect($update)->not->toBeNull()
        ->and($update->isPrivateUserChat())->toBeTrue()
        ->and($update->isEdit())->toBeFalse()
        ->and($update->idempotencyKey())->toBe('telegram:42:7')
        ->and($update->content())->toBe('halo')
        ->and($update->command())->toBeNull();
});

it('parses edits and distinguishes their versions', function () {
    $a = TelegramUpdate::fromArray(TelegramPayload::edited('x', messageId: 7, editDate: 100));
    $b = TelegramUpdate::fromArray(TelegramPayload::edited('x', messageId: 7, editDate: 200));

    expect($a->isEdit())->toBeTrue()
        ->and($a->idempotencyKey())->toBe($b->idempotencyKey())
        ->and($a->versionKey())->not->toBe($b->versionKey());
});

it('extracts command names', function (string $text, ?string $name) {
    $update = TelegramUpdate::fromArray(TelegramPayload::message($text));

    expect($update->command()['name'] ?? null)->toBe($name);
})->with([
    ['/start', 'start'],
    ['/Start', 'start'],
    ['/help@PakCarikk_bot', 'help'],
    ['/tasks extra words', 'tasks'],
    ['/update', 'update'],
    ["/start\nnext line", 'start'],
    ['not a /command', null],
    ['/', null],
    ['//double', null],
]);

it('returns null for updates it does not handle', function (array $payload) {
    expect(TelegramUpdate::fromArray($payload))->toBeNull();
})->with([
    [[]],
    [['update_id' => 1]],
    [['update_id' => 1, 'channel_post' => ['message_id' => 1]]],
    [['update_id' => 'x', 'message' => ['message_id' => 1]]],
    [['update_id' => 1, 'message' => 'not an array']],
]);

it('lists attachment types without file ids', function () {
    $update = TelegramUpdate::fromArray(TelegramPayload::message(null, extra: ['photo' => [['file_id' => 'AgAD-secret']], 'caption' => 'lihat']));

    expect($update->attachmentTypes)->toBe(['photo'])
        ->and($update->content())->toBe('lihat')
        ->and($update->unsupportedKind())->toBe('image');
});

it('fits text into the Telegram limit', function () {
    expect(TelegramText::fit('pendek', 10))->toBe('pendek')
        ->and(mb_strlen(TelegramText::fit(str_repeat('é', 5000), 4096)))->toBe(4096)
        ->and(TelegramText::fit(str_repeat('a', 50), 10))->toEndWith('…');
});

it('parses a button press', function () {
    $update = TelegramUpdate::fromArray(['update_id' => 9, 'callback_query' => [
        'id' => 'cb-1', 'from' => ['id' => 42, 'is_bot' => false], 'data' => 'a:5:undo',
        'message' => ['message_id' => 77, 'chat' => ['id' => 42, 'type' => 'private']],
    ]]);

    expect($update->isCallback())->toBeTrue()
        ->and($update->isPrivateUserChat())->toBeTrue()
        ->and($update->callbackData)->toBe('a:5:undo')
        ->and($update->callbackId)->toBe('cb-1')
        ->and($update->messageId)->toBe(77)
        ->and($update->content())->toBeNull()
        ->and($update->command())->toBeNull()
        ->and($update->versionKey())->toBe('callback:cb-1');
});

it('ignores malformed button presses', function (array $query) {
    expect(TelegramUpdate::fromArray(['update_id' => 1, 'callback_query' => $query]))->toBeNull();
})->with([
    'no message (inline mode)' => [['id' => 'x', 'from' => ['id' => 1], 'data' => 'a']],
    'no data (game)' => [['id' => 'x', 'from' => ['id' => 1], 'message' => ['message_id' => 1, 'chat' => ['id' => 1, 'type' => 'private']]]],
    'data not a string' => [['id' => 'x', 'from' => ['id' => 1], 'data' => 5, 'message' => ['message_id' => 1, 'chat' => ['id' => 1, 'type' => 'private']]]],
    'no from' => [['id' => 'x', 'data' => 'a', 'message' => ['message_id' => 1, 'chat' => ['id' => 1, 'type' => 'private']]]],
]);

it('reads which message a reply answers', function () {
    $update = TelegramUpdate::fromArray(TelegramPayload::message('bukan itu', extra: ['reply_to_message' => ['message_id' => 55]]));

    expect($update->replyToMessageId)->toBe(55)
        ->and(TelegramUpdate::fromArray(TelegramPayload::message('halo'))->replyToMessageId)->toBeNull();
});
