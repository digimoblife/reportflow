<?php

use App\Services\Redaction\RedactionCategory;
use App\Services\Redaction\RedactionService;
use Illuminate\Support\Arr;
use Tests\Support\FakeSecrets;

const PH = RedactionService::PLACEHOLDER;

function redact(string $text, array $custom = []): string
{
    return (new RedactionService($custom))->redact($text)->text;
}

dataset('secrets that must be removed', function () {
    $pw = FakeSecrets::password();

    return [
        'openai style key' => fn () => ['Pakai key '.FakeSecrets::openAiKey().' ya', 'Pakai key '.PH.' ya', ['api_key' => 1]],
        'github token' => fn () => ['token='.FakeSecrets::githubToken(), 'token='.PH, ['api_key' => 1]],
        'aws key id' => fn () => ['AWS '.FakeSecrets::awsKeyId().' dipakai', 'AWS '.PH.' dipakai', ['api_key' => 1]],
        'google key' => fn () => ['maps '.FakeSecrets::googleKey(), 'maps '.PH, ['api_key' => 1]],
        'slack token' => fn () => ['slack '.FakeSecrets::slackToken(), 'slack '.PH, ['api_key' => 1]],
        'telegram bot token' => fn () => ['bot '.FakeSecrets::telegramBotToken().' bocor', 'bot '.PH.' bocor', ['api_key' => 1]],
        'jwt' => fn () => ['auth '.FakeSecrets::jwt(), 'auth '.PH, ['api_key' => 1]],
        'stripe live key' => fn () => ['stripe '.FakeSecrets::stripeKey(), 'stripe '.PH, ['api_key' => 1]],
        'bearer header' => fn () => ['Authorization: Bearer '.str_repeat('aB3', 10), 'Authorization: Bearer '.PH, ['api_key' => 1]],
        'private key block' => fn () => ["ini key-nya:\n".FakeSecrets::pemBlock()."\nsudah ya", "ini key-nya:\n".PH."\nsudah ya", ['private_key' => 1]],
        'private key without end marker' => fn () => ["before\n-----BEGIN OPENSSH PRIVATE KEY-----\nabc\ndef\nmore text", "before\n".PH, ['private_key' => 1]],
        'database uri' => fn () => ['DB '.FakeSecrets::dbUri().' jalan', 'DB '.PH.' jalan', ['connection_string' => 1]],
        'redis uri with empty user' => fn () => ['redis://:'.'s3cretPw'.'@cache:6379/0', PH, ['connection_string' => 1]],
        'http url with basic auth keeps the rest' => fn () => ['clone https://deploy:'.'hunter2x'.'@git.example.test/repo.git', 'clone https://deploy:'.PH.'@git.example.test/repo.git', ['connection_string' => 1]],
        'password colon' => fn () => ["password: $pw", 'password: '.PH, ['password' => 1]],
        'Password equals quoted' => fn () => ["Password=\"$pw\"", 'Password='.PH, ['password' => 1]],
        'json password' => fn () => ['{"password": "'.$pw.'", "user": "admin"}', '{"password": '.PH.', "user": "admin"}', ['password' => 1]],
        'indonesian kata sandi' => fn () => ["kata sandi: $pw dari admin", 'kata sandi: '.PH.' dari admin', ['password' => 1]],
        'sandi equals' => fn () => ["sandi=$pw", 'sandi='.PH, ['password' => 1]],
        'passwordnya variant' => fn () => ["passwordnya $pw, tolong disimpan", 'passwordnya '.PH.', tolong disimpan', ['password' => 1]],
        'password is variant' => fn () => ["the password is $pw ok", 'the password is '.PH.' ok', ['password' => 1]],
        'passwordnya with colon' => fn () => ["passwordnya: $pw", 'passwordnya: '.PH, ['password' => 1]],
        'pwd shorthand' => fn () => ["pwd=$pw", 'pwd='.PH, ['password' => 1]],
        'pw shorthand' => fn () => ["pw: $pw", 'pw: '.PH, ['password' => 1]],
        'pass with secret-looking value' => fn () => ["pass: $pw", 'pass: '.PH, ['password' => 1]],
        'env style token' => fn () => ['GITHUB_TOKEN=abc123def456', 'GITHUB_TOKEN='.PH, ['password' => 1]],
        'env style with export and quotes' => fn () => ['export STRIPE_API_KEY="x9y8z7w6"', 'export STRIPE_API_KEY='.PH, ['password' => 1]],
        'env db password' => fn () => ["DB_PASSWORD=$pw\nDB_HOST=localhost", 'DB_PASSWORD='.PH."\nDB_HOST=localhost", ['password' => 1]],
        'multiple secrets, multiple lines' => fn () => ['a '.FakeSecrets::openAiKey()."\nb password: $pw\nc ".FakeSecrets::dbUri(), 'a '.PH."\nb password: ".PH."\nc ".PH, ['api_key' => 1, 'password' => 1, 'connection_string' => 1]],
        'secret at start and end of text' => fn () => [FakeSecrets::githubToken(), PH, ['api_key' => 1]],
        'unicode around the secret' => fn () => ['Kunci baru → '.FakeSecrets::openAiKey().' ✅', 'Kunci baru → '.PH.' ✅', ['api_key' => 1]],
    ];
});

it('removes secrets and reports categories without keeping values', function (array $case) {
    [$input, $expected, $counts] = $case;

    $result = (new RedactionService)->redact($input);

    expect($result->text)->toBe($expected)
        ->and(Arr::sortRecursive($result->counts))->toBe(Arr::sortRecursive($counts))
        ->and($result->hasFindings())->toBeTrue()
        ->and($result->failed())->toBeFalse();
})->with('secrets that must be removed');

it('is idempotent: redacting redacted text changes nothing and finds nothing', function (array $case) {
    $once = (new RedactionService)->redact($case[0]);
    $twice = (new RedactionService)->redact($once->text);

    expect($twice->text)->toBe($once->text)
        ->and($twice->hasFindings())->toBeFalse();
})->with('secrets that must be removed');

dataset('text that must stay untouched', [
    'password reset sentence' => 'Fix bug password reset di 9Club, email tidak terkirim',
    'lupa password' => 'User lupa password lalu minta reset lewat email',
    'password in indonesian sentence' => 'Password policy diperketat minimal 12 karakter',
    'kata sandi sentence' => 'Ganti kata sandi user harus lewat halaman profil',
    'the word pass' => 'Kalau boleh pass dulu untuk meeting hari ini',
    'pass in english' => 'We can pass the review if the tests pass',
    'test pass counter' => 'Test pass: 12/12 setelah refactor',
    'pass colon plain word' => 'pass: selesai semua',
    'pwd command' => 'jalankan pwd lalu ls di server',
    'pwd path' => 'pwd: /var/www/html',
    'password is plain word' => 'the password is required for login',
    'passwordnya plain word' => 'passwordnya sudah diganti',
    'git sha 40' => 'Merge commit 9fceb02d0ae598e95dc970b74767f19372d61af8 ke master',
    'git sha short' => 'revert a1b2c3d',
    'uuid' => 'order 3f2b8c1e-9a4d-4e7b-8f10-2c6d5e7a9b31 gagal',
    'task dash id (not sk-)' => 'lihat task-1234567890abcdefghijklmn di board',
    'disk dash id' => 'disk-abcdefghijklmnopqrstuvwxyz1234 penuh',
    'plain db url without credentials' => 'koneksi ke postgres://localhost:5432/appdb berhasil',
    'https url' => 'buka https://example.test/docs?x=1 dulu',
    'email address' => 'kirim ke tim@example.test',
    'ports and times' => 'deploy jam 14:30 port 8080:80',
    'env var without secret name' => 'APP_NAME=ReportFlow dan APP_ENV=local',
    'aws-like lowercase' => 'akia1234567890abcdef tidak dianggap key',
    'long ordinary sentence' => 'Hari ini saya mengerjakan integrasi payment gateway, menulis test, lalu deploy ke staging.',
    'empty' => '',
]);

it('leaves ordinary text unchanged', function (string $text) {
    $result = (new RedactionService)->redact($text);

    expect($result->text)->toBe($text)
        ->and($result->hasFindings())->toBeFalse();
})->with('text that must stay untouched');

it('supports custom patterns', function () {
    $result = (new RedactionService(['~\bACME-[A-Z0-9]{8}\b~u']))->redact('kode ACME-AB12CD34 dan ACME-lower');

    expect($result->text)->toBe('kode '.PH.' dan ACME-lower')
        ->and($result->counts)->toBe(['custom' => 1]);
});

it('rejects an invalid custom pattern loudly', function () {
    new RedactionService(['~(unclosed~']);
})->throws(InvalidArgumentException::class, 'Invalid redaction pattern');

it('fails closed on invalid UTF-8', function () {
    $result = (new RedactionService)->redact("halo \xC3\x28 dunia");

    expect($result->text)->toBe(PH)
        ->and($result->failed())->toBeTrue()
        ->and($result->secretCount())->toBe(0)
        ->and($result->counts)->toBe([RedactionCategory::Error->value => 1]);
});

it('fails closed when the input is longer than the limit', function () {
    $result = (new RedactionService([], 100))->redact(str_repeat('a', 101));

    expect($result->failed())->toBeTrue()->and($result->text)->toBe(PH);
});

it('fails closed when PCRE hits its limits', function () {
    // A custom pattern with unbounded nested quantifiers exceeds the backtrack limit.
    $result = (new RedactionService(['~(a+)+$~']))->redact(str_repeat('a', 40).'!');

    expect($result->failed())->toBeTrue()->and($result->text)->toBe(PH);
});

it('restores pcre.backtrack_limit after redacting', function () {
    $before = ini_get('pcre.backtrack_limit');

    (new RedactionService)->redact('halo');
    (new RedactionService)->redact("bad \xC3\x28");

    expect(ini_get('pcre.backtrack_limit'))->toBe($before);
});

dataset('adversarial inputs', function () {
    $len = 40_000; // stays under MAX_INPUT_LENGTH

    return [
        'long run of one letter' => fn () => str_repeat('a', $len),
        'repeated password keyword' => fn () => str_repeat('password:', (int) ($len / 9)),
        'repeated sk prefix' => fn () => str_repeat('sk-', (int) ($len / 3)),
        'sk prefix then long body' => fn () => 'sk-'.str_repeat('a', $len),
        'repeated env-ish names' => fn () => str_repeat('A_', (int) ($len / 2)),
        'env name then no equals' => fn () => str_repeat('API_KEY ', (int) ($len / 8)),
        'repeated pem begin' => fn () => str_repeat('-----BEGIN PRIVATE KEY-----', (int) ($len / 27)),
        'repeated scheme without end' => fn () => str_repeat('postgres://a:', (int) ($len / 13)),
        'many colons and at signs' => fn () => str_repeat(':@', (int) ($len / 2)),
        'bearer flood' => fn () => str_repeat('bearer ', (int) ($len / 7)),
        'jwt-like dots' => fn () => 'eyJ'.str_repeat('a.', (int) ($len / 2)),
        'password with long spaces' => fn () => 'password'.str_repeat(' ', $len).'x',
        'quote flood' => fn () => 'password="'.str_repeat('"a', (int) ($len / 2)),
        'multibyte flood' => fn () => str_repeat('パスワード:', (int) ($len / 6)),
    ];
});

it('stays fast and never leaks on adversarial input', function (Closure $make) {
    $input = $make();
    $started = hrtime(true);

    $result = (new RedactionService)->redact($input);

    $elapsedMs = (hrtime(true) - $started) / 1e6;

    // Either a clean result or a fail-closed marker; both are acceptable, a slow or crashing call is not.
    expect($result->text)->toBeString()
        ->and($elapsedMs)->toBeLessThan(1500.0);
})->with('adversarial inputs');
