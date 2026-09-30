<?php

namespace App\Providers;

use App\Database\PostgresConnection;
use App\Services\Ai\AiProvider;
use App\Services\Ai\Fakes\FakeAiProvider;
use App\Services\Telegram\BotMessages;
use App\Services\Telegram\Fakes\FakeTelegramClient;
use App\Services\Telegram\HttpTelegramClient;
use App\Services\Telegram\TelegramClient;
use App\Support\UserContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(UserContext::class);

        $this->app->singleton(TelegramClient::class, fn (): TelegramClient => match (config('telegram.client')) {
            'http' => new HttpTelegramClient,
            'fake' => new FakeTelegramClient,
            default => throw new RuntimeException('TELEGRAM_CLIENT must be "http" or "fake".'),
        });

        // TODO(M3): the DeepSeek provider; "fake" is the only provider until then.
        $this->app->singleton(AiProvider::class, fn (): AiProvider => match (config('ai.provider')) {
            'fake' => new FakeAiProvider,
            default => throw new RuntimeException('AI_PROVIDER must be "fake" until the DeepSeek provider exists (M3).'),
        });

        $this->app->singleton(BotMessages::class);

        Connection::resolverFor('pgsql', fn ($connection, $database, $prefix, $config) => new PostgresConnection($connection, $database, $prefix, $config));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A fake Telegram client in production would silently swallow every user-facing message.
        if ($this->app->isProduction() && config('telegram.client') !== 'http') {
            throw new RuntimeException('TELEGRAM_CLIENT must be "http" in production.');
        }

        // Telegram delivers from a small set of addresses; the limit is generous but stops floods.
        RateLimiter::for('telegram-webhook', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip() ?? 'unknown'));
    }
}
