<?php

use App\Enums\Language;
use App\Services\Telegram\LanguageDetector;

it('detects the language of clear messages', function (string $text, Language $expected) {
    expect((new LanguageDetector)->detect($text, Language::Indonesian))->toBe($expected);

    $flipped = $expected === Language::Indonesian ? Language::English : Language::Indonesian;
    expect((new LanguageDetector)->detect($text, $flipped))->toBe($expected);
})->with([
    ['Hari ini saya sudah selesai memperbaiki bug login dan deploy ke staging', Language::Indonesian],
    ['Tadi pagi kerja di project 9Club, masih menunggu klien untuk konfirmasi', Language::Indonesian],
    ['Today I fixed the login bug and we are still waiting for the client', Language::English],
    ['Finished the deploy to staging and worked on the report for this week', Language::English],
]);

it('falls back to the default when the message is ambiguous or too short', function (string $text) {
    expect((new LanguageDetector)->detect($text, Language::English))->toBe(Language::English)
        ->and((new LanguageDetector)->detect($text, Language::Indonesian))->toBe(Language::Indonesian);
})->with(['deploy 9Club', 'ok', '', '12345', 'fix bug login', 'sudah']);

it('is deterministic', function () {
    $detector = new LanguageDetector;
    $results = array_map(fn () => $detector->detect('the login is done and the client is happy', Language::Indonesian), range(1, 20));

    expect(array_unique(array_map(fn ($l) => $l->value, $results)))->toBe(['en']);
});
