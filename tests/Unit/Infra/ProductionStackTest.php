<?php

use Symfony\Component\Process\Process;

// PRD §56, §83 (M9e): static guards for the production edge. The live check (nginx -t, curl with a self-signed
// certificate) is in docs/runbooks/deploy.md and was run when this was written.

function prodConf(): string
{
    return (string) file_get_contents(repoFile('docker/nginx/prod.conf.template'));
}

/** Config without comment lines. */
function prodDirectives(): string
{
    return implode("\n", array_filter(explode("\n", prodConf()), fn (string $l) => ! str_starts_with(trim($l), '#')));
}

describe('nginx edge', function () {
    it('allows only TLS 1.2 and 1.3 and refuses handshakes for any other name', function () {
        $c = prodDirectives();

        expect($c)->toContain('ssl_protocols TLSv1.2 TLSv1.3;')->and($c)->toContain('ssl_reject_handshake on;')
            ->and($c)->toContain('ssl_session_tickets off;')->and($c)->not->toMatch('/TLSv1[ ;]|TLSv1\.1|SSLv/');
    });

    it('sends the security headers on every response and never replaces them inside a location', function () {
        $c = prodDirectives();

        foreach (['Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy'] as $header) {
            expect($c)->toMatch('/add_header '.$header.' "[^"]+" always;/');
        }
        // add_header inside a location would drop the server-level ones.
        preg_match_all('/location[^{]*\{[^}]*add_header/s', $c, $m);
        expect($m[0])->toBe([]);
    });

    it('redirects port 80 to the configured domain, not to the Host header, and serves only the ACME challenge', function () {
        $c = prodDirectives();

        expect($c)->toContain('return 301 https://${APP_DOMAIN}$request_uri;')->and($c)->not->toContain('https://$host')
            ->and($c)->toContain('location /.well-known/acme-challenge/');
    });

    it('rate limits logins and everything else, and limits body sizes', function () {
        $c = prodDirectives();

        expect($c)->toContain('limit_req zone=login')->and($c)->toContain('limit_req zone=general')->and($c)->toContain('limit_conn perip')
            ->and($c)->toMatch('/client_max_body_size 2m;/')->and($c)->toMatch('/client_max_body_size 1m;/');
    });

    it('runs PHP only through the front controller and hides dotfiles', function () {
        $c = prodDirectives();

        expect($c)->toContain('location ~ ^/index\.php(/|$)')->and($c)->toContain('location ~ \.php$ { return 404; }')
            ->and($c)->toContain('location ~ /\.(?!well-known).*');
    });

    it('exposes the webhook as one exact POST path with the secret header, without logging it', function () {
        $c = prodDirectives();

        expect($c)->toContain('location = /${TELEGRAM_WEBHOOK_PATH}')->and($c)->toContain('if ($request_method != POST) { return 404; }')
            ->and($c)->toContain('$http_x_telegram_bot_api_secret_token = ""');
        preg_match('/location = \/\$\{TELEGRAM_WEBHOOK_PATH\} \{(.*?)\n    \}/s', $c, $m);
        expect($m[1])->toContain('access_log off;')->and($m[1])->not->toContain('error_log');
    });

    it('only substitutes its own two variables, so nginx variables survive envsubst', function () {
        $compose = (string) file_get_contents(repoFile('docker-compose.prod.yml'));

        expect($compose)->toContain('NGINX_ENVSUBST_FILTER=^(APP_DOMAIN|TELEGRAM_WEBHOOK_PATH)$$');
    });
});

describe('production compose', function () {
    beforeEach(fn () => $this->prod = (string) file_get_contents(repoFile('docker-compose.prod.yml')));

    it('bakes the code into the image and bind-mounts none of it', function () {
        expect($this->prod)->toContain('target: prod')->and($this->prod)->not->toContain('.:/var/www/html')->and($this->prod)->not->toContain('init-test-db')
            ->and($this->prod)->toContain('volumes: !override');
    });

    it('publishes only 80 and 443 on nginx and nothing on the data stores', function () {
        expect($this->prod)->toContain('- "80:80"')->and($this->prod)->toContain('- "443:443"')
            ->and(preg_match_all('/^\s+ports:/m', $this->prod))->toBe(1)
            ->and($this->prod)->not->toContain('8081')->and($this->prod)->not->toContain('127.0.0.1:80')->and($this->prod)->not->toContain('127.0.0.1:443');
    });

    it('requires the domain and the webhook path instead of defaulting them', function () {
        expect($this->prod)->toContain('${APP_DOMAIN:?')->and($this->prod)->toContain('${TELEGRAM_WEBHOOK_PATH:?');
    });

    it('takes its environment from the host .env, never from the image', function () {
        $docker = (string) file_get_contents(repoFile('.dockerignore'));

        expect($this->prod)->toContain('env_file')->and($docker)->toContain('.env')->and($docker)->toContain('secrets')->and($docker)->toContain('storage/app')
            ->and($docker)->toContain('bootstrap/cache/*.php');
    });

    it('does not leave the dev stage as the production target or bake dev dependencies in', function () {
        $dockerfile = (string) file_get_contents(repoFile('docker/php/Dockerfile'));

        expect($dockerfile)->toContain('FROM base AS prod')->and($dockerfile)->toContain('composer install --no-dev')
            ->and(strrpos($dockerfile, 'FROM base AS dev'))->toBeGreaterThan(strrpos($dockerfile, 'FROM base AS prod'));
    });
});

it('has a certificate script that parses and a deploy path for renewals', function () {
    $script = repoFile('scripts/tls/issue.sh');
    $check = new Process(['bash', '-n', $script]);
    $check->run();

    expect(is_executable($script))->toBeTrue()->and($check->isSuccessful())->toBeTrue()
        ->and((string) file_get_contents(repoFile('docker-compose.prod.yml')))->toContain('certbot renew');
});
