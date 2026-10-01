<?php

use App\Services\Telegram\ViewData;

it('round-trips navigation payloads', function (ViewData $data, string $encoded) {
    expect($data->encode())->toBe($encoded)
        ->and(ViewData::parse($encoded))->toEqual($data);
})->with([
    'page' => [new ViewData('tasks', 2), 'v:tasks:2'],
    'ref' => [new ViewData('task', 0, 123), 'v:task:0:123'],
]);

it('ignores anything that is not a navigation payload', function (string $raw) {
    expect(ViewData::parse($raw))->toBeNull();
})->with(['', 'v:', 'v:tasks', 'v:Tasks:1', 'v:tasks:100', 'v:tasks:1:0', "v:tasks:1\n", 'a:1:undo', 'v:tasks:1:abc', 'v:tasks:1:2:3']);
