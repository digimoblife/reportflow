<?php

use App\Models\InboundMessage;
use App\Services\Telegram\CallbackContext;
use App\Services\Telegram\CallbackRouter;
use App\Services\Telegram\TelegramMessenger;
use Illuminate\Support\Facades\Queue;

function callbackPayload(string $data, int $from = 555001, string $id = 'cb-1', int $messageId = 900): array
{
    return ['update_id' => random_int(1000, 999999), 'callback_query' => [
        'id' => $id, 'from' => ['id' => $from, 'is_bot' => false], 'data' => $data,
        'message' => ['message_id' => $messageId, 'chat' => ['id' => $from, 'type' => 'private']],
    ]];
}

beforeEach(function () {
    Queue::fake();
    $this->user = registerTelegramUser(555001);
    $this->message = asUser($this->user->id, fn () => InboundMessage::factory()->create(['user_id' => $this->user->id]));
    $this->seen = [];
    app(CallbackRouter::class)->on('ping', function (CallbackContext $c) {
        $this->seen[] = [$c->data->action, $c->data->item, $c->data->arg, $c->message->id, $c->bubbleMessageId()];
        app(TelegramMessenger::class)->tryAnswer($c->callbackId(), 'pong');
    });
});

it('routes a button press to its handler with the owned message', function () {
    postTelegram(callbackPayload("a:{$this->message->id}:ping:2:abc"))->assertOk();

    expect($this->seen)->toBe([['ping', 2, 'abc', $this->message->id, 900]])
        ->and(fakeTelegram()->answers)->toBe([['id' => 'cb-1', 'text' => 'pong']]);
});

it('handles a re-delivered press only once', function () {
    $payload = callbackPayload("a:{$this->message->id}:ping");

    postTelegram($payload)->assertOk();
    postTelegram($payload)->assertOk();

    expect($this->seen)->toHaveCount(1);
});

it('answers "expired" and does nothing for unknown actions, malformed data and missing messages', function (string $data) {
    postTelegram(callbackPayload($data))->assertOk();

    expect($this->seen)->toBe([])
        ->and(fakeTelegram()->answers)->toHaveCount(1)
        ->and(isVariantOf(fakeTelegram()->answers[0]['text'], 'callback.expired'))->toBeTrue();
})->with(['unknown action' => fn () => "a:{$this->message->id}:nope", 'garbage' => 'hello', 'missing message' => 'a:99999999:ping']);

it('never lets another user press buttons of this user\'s message', function () {
    $intruder = registerTelegramUser(777001);

    postTelegram(callbackPayload("a:{$this->message->id}:ping", from: 777001))->assertOk();

    expect($this->seen)->toBe([])
        ->and($intruder->id)->not->toBe($this->user->id)
        ->and(isVariantOf(fakeTelegram()->answers[0]['text'], 'callback.expired'))->toBeTrue();
});

it('ignores button presses from unregistered users without any answer', function () {
    postTelegram(callbackPayload("a:{$this->message->id}:ping", from: 999888))->assertOk();

    expect($this->seen)->toBe([])->and(fakeTelegram()->answers)->toBe([]);
});
