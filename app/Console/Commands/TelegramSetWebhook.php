<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:set-webhook {--dry-run : Print the payload (secrets hidden) and do not call Telegram}')]
#[Description('Register the Telegram webhook (URL from APP_URL + TELEGRAM_WEBHOOK_PATH, with the secret token)')]
class TelegramSetWebhook extends Command
{
    /** PRD §56: the bot subscribes to nothing else. */
    private const ALLOWED_UPDATES = ['message', 'edited_message'];

    public function handle(TelegramClient $client): int
    {
        $errors = [];

        $path = config('telegram.webhook_path');
        $secret = config('telegram.secret_token');
        $base = rtrim((string) config('app.url'), '/');
        $allowed = config('telegram.allowed_updates');

        if (! is_string($path) || strlen($path) < 32 || preg_match('/^[A-Za-z0-9_-]+$/', $path) !== 1) {
            $errors[] = 'TELEGRAM_WEBHOOK_PATH must be at least 32 characters of A-Z a-z 0-9 _ -.';
        }

        if (! is_string($secret) || preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret) !== 1) {
            $errors[] = 'TELEGRAM_BOT_SECRET_TOKEN must be 1 to 256 characters of A-Z a-z 0-9 _ -.';
        }

        if (! str_starts_with($base, 'https://')) {
            $errors[] = 'APP_URL must be an https:// URL (Telegram only delivers webhooks over HTTPS).';
        }

        if ($allowed !== self::ALLOWED_UPDATES) {
            $errors[] = 'allowed_updates must be exactly [message, edited_message].';
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        /** @var string $path */
        /** @var string $secret */
        $url = $base.'/'.$path;

        if ($this->option('dry-run')) {
            $this->line(json_encode([
                'method' => 'setWebhook',
                'url' => $base.'/'.substr($path, 0, 4).'…[hidden, '.strlen($path).' chars]',
                'secret_token' => '[hidden, '.strlen($secret).' chars]',
                'allowed_updates' => self::ALLOWED_UPDATES,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->components->info('Dry run: nothing was sent to Telegram.');

            return self::SUCCESS;
        }

        try {
            $client->setWebhook($url, $secret, self::ALLOWED_UPDATES);
        } catch (TelegramApiException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Webhook registered.');

        return self::SUCCESS;
    }
}
