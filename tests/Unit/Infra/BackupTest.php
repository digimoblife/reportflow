<?php

use Symfony\Component\Process\Process;

// PRD §84: the backup scripts and service. The real run (pg_dump, openssl, rclone, restore) is a manual/runbook check;
// here: syntax, retention logic and the compose wiring.

function backupScript(string $name): string
{
    return repoFile("scripts/backup/{$name}");
}

/** @param  list<string>  $command */
function sh(array $command, array $env = []): Process
{
    $process = new Process($command, null, $env);
    $process->run();

    return $process;
}

it('has scripts that parse and are executable', function (string $script) {
    expect(is_executable(backupScript($script)))->toBeTrue()
        ->and(sh(['bash', '-n', backupScript($script)])->isSuccessful())->toBeTrue();
})->with(['lib.sh', 'prune.sh', 'backup.sh', 'restore-test.sh', 'restore.sh']);

it('never deletes with an unguarded variable', function () {
    foreach (glob(repoFile('scripts/backup/*.sh')) as $file) {
        foreach (preg_split('/\R/', (string) file_get_contents($file)) as $line) {
            if (preg_match('/\brm\b.*\$/', $line)) {
                expect($line)->toMatch('/\$\{[a-z_]+:\?\}/');
            }
        }
    }
});

describe('retention', function () {
    beforeEach(function () {
        $this->dir = sys_get_temp_dir().'/rf-prune-'.uniqid();
        mkdir($this->dir);
        foreach (['20261001T000000Z', '20261002T000000Z', '20261003T000000Z', '20261004T000000Z', '20261005T000000Z'] as $stamp) {
            touch("{$this->dir}/reportflow-{$stamp}.tar.enc");
            touch("{$this->dir}/reportflow-{$stamp}.tar.enc.sha256");
        }
        touch("{$this->dir}/notes.txt");   // not an archive: never touched
    });

    afterEach(function () {
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        rmdir($this->dir);
    });

    it('keeps the newest N archives with their checksums and leaves other files alone', function () {
        $result = sh(['bash', backupScript('prune.sh'), 'local', $this->dir, '2']);

        expect($result->isSuccessful())->toBeTrue()
            ->and(array_map('basename', glob("{$this->dir}/*")))->toBe([
                'notes.txt',
                'reportflow-20261004T000000Z.tar.enc', 'reportflow-20261004T000000Z.tar.enc.sha256',
                'reportflow-20261005T000000Z.tar.enc', 'reportflow-20261005T000000Z.tar.enc.sha256',
            ]);
    });

    it('does nothing when there are fewer archives than the limit, and refuses nonsense', function () {
        expect(sh(['bash', backupScript('prune.sh'), 'local', $this->dir, '9'])->isSuccessful())->toBeTrue()
            ->and(glob("{$this->dir}/reportflow-*.tar.enc"))->toHaveCount(5)
            ->and(! sh(['bash', backupScript('prune.sh'), 'local', $this->dir, '0'])->isSuccessful())->toBeTrue()
            ->and(! sh(['bash', backupScript('prune.sh'), 'local', '', '3'])->isSuccessful())->toBeTrue()
            ->and(glob("{$this->dir}/reportflow-*.tar.enc"))->toHaveCount(5);
    });
});

describe('restore safety', function () {
    it('refuses to restore without --yes, over the live database, or into a non-empty directory', function () {
        $archive = tempnam(sys_get_temp_dir(), 'rf');
        $env = ['PGDATABASE' => 'live', 'KEY_FILE' => $archive];

        $run = fn (array $args) => sh(['bash', backupScript('restore.sh'), '--archive', $archive, ...$args], $env);

        expect($run(['--database', 'restored_db', '--files', sys_get_temp_dir().'/rf-new-'.uniqid()])->getErrorOutput())->toContain('refusing without --yes')
            ->and($run(['--database', 'live', '--files', sys_get_temp_dir().'/rf-new-'.uniqid(), '--yes'])->getErrorOutput())->toContain('live database')
            ->and($run(['--database', 'restored_db', '--files', sys_get_temp_dir(), '--yes'])->getErrorOutput())->toContain('not empty');
        unlink($archive);
    });
});

describe('the backup service', function () {
    it('is opt-in, mounts the key and rclone config read-only, keeps the code read-only and publishes no port', function () {
        $raw = (string) file_get_contents(repoFile('docker-compose.yml'));
        $svc = compose()['backup'];

        expect($raw)->toContain('profiles: ["backup"]')
            ->and($svc['volumes'])->toContain('.:/app:ro')
            ->and(implode("\n", $svc['volumes']))->toContain('/run/secrets/backup_key:ro')->toContain('/config/rclone:ro')
            ->and($svc)->not->toHaveKey('ports');
    });

    it('keeps the key, rclone config and local archives out of git', function () {
        $ignore = (string) file_get_contents(repoFile('.gitignore'));

        expect($ignore)->toContain('/secrets/')->toContain('/storage/app/backup/');
    });

    it('bakes no secret into the image', function () {
        $files = (string) file_get_contents(repoFile('docker/backup/Dockerfile')).(string) file_get_contents(repoFile('docker/backup/entrypoint.sh'));

        expect($files)->not->toMatch('/PASSWORD|SECRET|\.key|rclone\.conf/i')->and($files)->toContain('COPY scripts/backup');
    });

    it('lets the scheduler reach Telegram so ops:check can alert', function () {
        expect(compose()['scheduler']['networks'])->toContain('reportflow-public');
    });
});
