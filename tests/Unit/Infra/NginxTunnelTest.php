<?php

// Static guard for the tunnel-facing nginx block and the compose port layout
// (docs/runbooks/telegram-live-test.md). The live check is scripts/verify-tunnel-port.sh.

function repoFile(string $path): string
{
    return dirname(__DIR__, 3).'/'.$path;
}

function tunnelTemplate(): string
{
    return (string) file_get_contents(repoFile('docker/nginx/tunnel.conf.template'));
}

/** The template without comment lines. */
function tunnelDirectives(): string
{
    return implode("\n", array_filter(explode("\n", tunnelTemplate()), fn (string $l) => ! str_starts_with(trim($l), '#')));
}

/**
 * Minimal reader for the parts of docker-compose.yml these tests need (no YAML dependency):
 * per service, the `ports`, `environment` and `volumes` list items.
 *
 * @return array<string, array<string, list<string>>>
 */
function compose(): array
{
    $services = [];
    $inServices = false;
    $service = $section = null;

    foreach (explode("\n", (string) file_get_contents(repoFile('docker-compose.yml'))) as $line) {
        if (preg_match('/^services:\s*$/', $line)) {
            $inServices = true;

            continue;
        }

        if ($inServices && preg_match('/^\S/', $line) && trim($line) !== '') {
            break; // next top-level key (networks:, volumes:)
        }

        if (preg_match('/^  ([a-z0-9_-]+):\s*$/', $line, $m)) {
            $service = $m[1];
            $services[$service] = [];
            $section = null;
        } elseif ($service !== null && preg_match('/^    (ports|environment|volumes):\s*$/', $line, $m)) {
            $section = $m[1];
            $services[$service][$section] = [];
        } elseif ($service !== null && preg_match('/^    [a-z_]+:/', $line)) {
            $section = null;
        } elseif ($service !== null && $section !== null && preg_match('/^\s+-\s+"?([^"#]+?)"?\s*(?:#.*)?$/', $line, $m)) {
            $services[$service][$section][] = $m[1];
        }
    }

    return $services;
}

it('listens on 8081 only', function () {
    preg_match_all('/^\s*listen\s+([^;]+);/m', tunnelDirectives(), $m);

    expect($m[1])->toBe(['8081']);
});

it('hands exactly one location to PHP-FPM, and it is the exact webhook path', function () {
    $conf = tunnelDirectives();

    expect(substr_count($conf, 'fastcgi_pass'))->toBe(1)
        ->and(preg_match_all('/^\s*location\s/m', $conf))->toBe(2);

    preg_match_all('/^\s*location\s+(.+?)\s*\{\s*$/m', $conf, $locations);
    expect(array_map('trim', $locations[1]))->toBe(['= /${TELEGRAM_WEBHOOK_PATH}', '/']);

    // fastcgi_pass lives inside the exact-match block.
    preg_match('/location = \/\$\{TELEGRAM_WEBHOOK_PATH\}\s*\{(.*?)\n    \}/s', $conf, $block);
    expect($block[1] ?? '')->toContain('fastcgi_pass app:9000;');
});

it('answers 404 to every other path', function () {
    preg_match('/location \/ \{(.*?)\}/s', tunnelDirectives(), $block);

    expect(trim($block[1]))->toBe('return 404;');
});

it('has no regex locations (no .php handler that could run other scripts)', function () {
    expect(tunnelDirectives())->not->toMatch('/location\s+[~^]/')->not->toContain('\.php');
});

it('accepts only POST with a non-empty Telegram secret header', function () {
    $conf = tunnelDirectives();

    expect($conf)->toContain('if ($request_method != POST) { return 404; }')
        ->and($conf)->toContain('if ($http_x_telegram_bot_api_secret_token = "") { return 404; }');
});

it('does not log the secret path and does not raise nginx log levels', function () {
    $conf = tunnelDirectives();

    expect($conf)->toContain('access_log off;')
        ->and(substr_count($conf, 'access_log'))->toBe(1)
        ->and($conf)->not->toContain('error_log')
        ->and(file_get_contents(repoFile('docker/nginx/default.conf')))->not->toContain('error_log');
});

it('keeps the request body small and server tokens off', function () {
    expect(tunnelDirectives())->toContain('client_max_body_size 1m;')->toContain('server_tokens off;');
});

it('publishes only nginx ports 80 and 8081, both on 127.0.0.1', function () {
    $services = compose();
    $published = [];

    foreach ($services as $name => $service) {
        foreach ($service['ports'] ?? [] as $port) {
            $published[$name][] = $port;
        }
    }

    expect(array_keys($published))->toBe(['nginx'])
        ->and($published['nginx'])->toBe(['127.0.0.1:80:80', '127.0.0.1:8081:8081']);
});

it('publishes nothing for postgres, redis, gotenberg, app, worker and scheduler', function (string $service) {
    expect(compose()[$service])->not->toHaveKey('ports');
})->with(['postgres', 'redis', 'gotenberg', 'app', 'worker', 'scheduler']);

it('wires the template and a safe default path into the nginx service', function () {
    $nginx = compose()['nginx'];

    expect($nginx['volumes'])->toContain('./docker/nginx/tunnel.conf.template:/etc/nginx/templates/tunnel.conf.template:ro')
        ->and($nginx['environment'])->toContain('TELEGRAM_WEBHOOK_PATH=${TELEGRAM_WEBHOOK_PATH:-webhook-path-not-configured}');
});

it('never puts the webhook path itself in a tracked file', function () {
    // The template only references the variable; compose only interpolates it.
    expect(tunnelTemplate())->toContain('${TELEGRAM_WEBHOOK_PATH}')
        ->and(file_get_contents(repoFile('.env.example')))->toMatch('/^TELEGRAM_WEBHOOK_PATH=\s*$/m');
});
