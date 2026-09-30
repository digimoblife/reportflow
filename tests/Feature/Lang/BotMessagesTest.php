<?php

use App\Enums\Language;
use App\Services\Telegram\BotCommandRegistry;
use App\Services\Telegram\BotMessages;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;

// Rules from .claude/skills/pak-carik-messages/SKILL.md.

/** Javanese words the persona may use (PRD §19). Each occurrence counts, phrases are not exempt. */
const ALLOWED_JAVANESE = ['nggih', 'nuwun', 'sewu', 'rampung', 'monggo', 'sugeng', 'rawuh', 'njenengan', 'matur', 'waduh'];

function botTemplates(string $locale): array
{
    return Arr::dot(Lang::get('bot', [], $locale, false));
}

/** Maps "area.situation.0" dotted keys to ["area.situation" => [variants]]. */
function botKeys(string $locale): array
{
    $keys = [];
    foreach (botTemplates($locale) as $dotted => $text) {
        $keys[preg_replace('/\.\d+$/', '', $dotted)][] = $text;
    }

    return $keys;
}

function placeholders(string $text): array
{
    preg_match_all('/(?<![A-Za-z0-9]):[a-z_]+\b/', $text, $m);
    $found = array_unique($m[0]);
    sort($found);

    return $found;
}

function javaneseWordCount(string $text): int
{
    $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return count(array_filter($words, fn (string $w) => in_array($w, ALLOWED_JAVANESE, true)));
}

it('has an English counterpart for every Indonesian key, and no extras', function () {
    expect(array_keys(botKeys('en')))->toEqualCanonicalizing(array_keys(botKeys('id')));
});

it('has 2 to 3 variants per key in both languages', function (string $locale) {
    foreach (botKeys($locale) as $key => $variants) {
        expect(count($variants))->toBeBetween(2, 3, "bot.$key ($locale)");
    }
})->with(['id', 'en']);

it('has no empty variants', function (string $locale) {
    foreach (botTemplates($locale) as $key => $text) {
        expect(trim((string) $text))->not->toBe('', "bot.$key ($locale)");
    }
})->with(['id', 'en']);

it('uses identical placeholders in every variant of a key, in both languages', function () {
    $id = botKeys('id');
    $en = botKeys('en');

    foreach ($id as $key => $variants) {
        $reference = placeholders($variants[0]);

        foreach ([...$variants, ...$en[$key]] as $variant) {
            expect(placeholders($variant))->toBe($reference, "bot.$key: $variant");
        }
    }
});

it('uses at most two Javanese words per Indonesian variant', function () {
    foreach (botTemplates('id') as $key => $text) {
        expect(javaneseWordCount($text))->toBeLessThanOrEqual(2, "bot.$key: $text");
    }
});

it('has no Javanese in English messages', function () {
    foreach (botTemplates('en') as $key => $text) {
        expect(javaneseWordCount($text))->toBe(0, "bot.$key: $text");
    }
});

it('fills placeholders and picks a variant through the injected picker', function () {
    $messages = new BotMessages(fn (int $count) => $count - 1);

    expect($messages->get('commands.unavailable', Language::Indonesian, ['command' => '/tasks']))
        ->toBe('Maaf, /tasks belum bisa dipakai. Ketik /help untuk perintah yang sudah aktif.')
        ->and($messages->get('onboarding.project_created', 'en', ['project' => '9Club']))
        ->toBe('Done, project "9Club" is in the archive. Go ahead and tell me about your work.');
});

it('can produce every variant of every key with random picking', function () {
    $messages = new BotMessages;

    foreach (botKeys('id') as $key => $variants) {
        foreach ([Language::Indonesian, Language::English] as $language) {
            $text = $messages->get($key, $language, ['count' => 2, 'project' => 'X', 'command' => '/x']);
            expect($text)->not->toBe('')->and($text)->not->toContain(':count')->not->toContain(':project')->not->toContain(':command');
        }
    }
});

it('throws for an unknown key', function () {
    (new BotMessages)->get('nope.nothing', Language::Indonesian);
})->throws(InvalidArgumentException::class);

it('registers exactly the commands of PRD §20, without /update', function () {
    $expected = ['start', 'help', 'projects', 'project', 'tasks', 'task', 'undo', 'inbox', 'report', 'reports', 'review', 'generate', 'settings', 'reminder'];
    $registry = new BotCommandRegistry;

    expect(array_map(fn ($c) => $c->name, $registry->all()))->toBe($expected)
        ->and($registry->find('update'))->toBeNull()
        ->and(array_map(fn ($c) => $c->name, array_filter($registry->all(), fn ($c) => $c->available)))->toBe(['start', 'help']);
});

it('describes every command in both languages within Telegram limits', function () {
    $registry = new BotCommandRegistry;

    foreach ([Language::Indonesian, Language::English] as $language) {
        $payload = $registry->payload($language);
        expect($payload)->toHaveCount(14);

        foreach ($payload as $entry) {
            expect($entry['command'])->toMatch('/^[a-z0-9_]{1,32}$/')
                ->and(mb_strlen($entry['description']))->toBeBetween(3, 256)
                ->and($entry['description'])->not->toStartWith('bot_commands.');
        }
    }

    expect($registry->payload(Language::Indonesian)[0])->toBe(['command' => 'start', 'description' => 'Mulai dan buat project pertama']);
});
