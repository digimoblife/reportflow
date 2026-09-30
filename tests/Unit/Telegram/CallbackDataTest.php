<?php

use App\Services\Telegram\CallbackData;
use App\Services\Telegram\Keyboard;

it('round-trips every shape', function (CallbackData $data, string $encoded) {
    expect($data->encode())->toBe($encoded)
        ->and(CallbackData::parse($encoded))->toEqual($data)
        ->and(strlen($encoded))->toBeLessThanOrEqual(64);
})->with([
    'action only' => [new CallbackData(42, 'undo'), 'a:42:undo'],
    'with item' => [new CallbackData(42, 'undo', 1), 'a:42:undo:1'],
    'with item and arg' => [new CallbackData(42, 'status', 0, 'completed'), 'a:42:status:0:completed'],
    'arg without item' => [new CallbackData(42, 'pick', null, 'x9'), 'a:42:pick::x9'],
    'large id' => [new CallbackData(999_999_999_999, 'move', 12, 'new'), 'a:999999999999:move:12:new'],
]);

it('rejects anything that is not exactly our format', function (string $raw) {
    expect(CallbackData::parse($raw))->toBeNull();
})->with([
    '', 'undo', 'a:x:undo', 'a:0:undo', 'a:1:UNDO', 'a:1:', 'a:1:undo:abc', 'a:1:undo:1:bad arg', 'a:1:undo:1:2:3',
    'b:1:undo', ' a:1:undo', "a:1:undo\n", str_repeat('a', 65), 'a:1:undo:100', 'a:-1:undo',
]);

it('refuses to build invalid data', function (Closure $make) {
    $make();
})->with([
    'zero id' => [fn () => new CallbackData(0, 'undo')],
    'bad action' => [fn () => new CallbackData(1, 'Undo!')],
    'item too large' => [fn () => new CallbackData(1, 'undo', 100)],
    'arg too long' => [fn () => new CallbackData(1, 'undo', 0, str_repeat('a', 21))],
    'arg with space' => [fn () => new CallbackData(1, 'undo', 0, 'a b')],
])->throws(InvalidArgumentException::class);

it('builds keyboards in rows', function () {
    $buttons = array_map(fn (int $i) => Keyboard::button("B{$i}", new CallbackData(1, 'undo', $i)), range(1, 5));

    $rows = Keyboard::rows($buttons, 2);

    expect($rows)->toHaveCount(3)->and($rows[0])->toHaveCount(2)->and($rows[2])->toHaveCount(1)
        ->and($rows[0][0])->toBe(['text' => 'B1', 'callback_data' => 'a:1:undo:1']);
});
