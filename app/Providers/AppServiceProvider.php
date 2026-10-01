<?php

namespace App\Providers;

use App\Database\PostgresConnection;
use App\Http\Middleware\BindUserContext;
use App\Services\Ai\AiProvider;
use App\Services\Ai\DeepSeekProvider;
use App\Services\Ai\Fakes\FakeAiProvider;
use App\Services\Ops\DefaultServiceProbe;
use App\Services\Ops\FakeServiceProbe;
use App\Services\Ops\HealthChecks;
use App\Services\Ops\ServiceProbe;
use App\Services\Report\Pdf\FakePdfRenderer;
use App\Services\Report\Pdf\GotenbergPdfRenderer;
use App\Services\Report\Pdf\PdfRenderer;
use App\Services\Telegram\BotMessages;
use App\Services\Telegram\CallbackRouter;
use App\Services\Telegram\CorrectionHandler;
use App\Services\Telegram\Fakes\FakeTelegramClient;
use App\Services\Telegram\HttpTelegramClient;
use App\Services\Telegram\NavigationHandler;
use App\Services\Telegram\PendingAnswerHandler;
use App\Services\Telegram\ReminderHandler;
use App\Services\Telegram\ReportCommands;
use App\Services\Telegram\ReportReviewHandler;
use App\Services\Telegram\TelegramClient;
use App\Services\Worklog\Extraction\ConfidencePolicy;
use App\Support\UserContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(UserContext::class);

        $this->app->singleton(ServiceProbe::class, fn (): ServiceProbe => match (config('ops.probe')) {
            'real' => new DefaultServiceProbe,
            'fake' => new FakeServiceProbe,
            default => throw new RuntimeException('OPS_PROBE must be "real" or "fake".'),
        });

        $this->app->singleton(PdfRenderer::class, fn (): PdfRenderer => match (config('reports.pdf.renderer')) {
            'gotenberg' => new GotenbergPdfRenderer((string) config('reports.pdf.url'), (int) config('reports.pdf.timeout')),
            'fake' => new FakePdfRenderer,
            default => throw new RuntimeException('PDF_RENDERER must be "gotenberg" or "fake".'),
        });

        $this->app->singleton(TelegramClient::class, fn (): TelegramClient => match (config('telegram.client')) {
            'http' => new HttpTelegramClient,
            'fake' => new FakeTelegramClient,
            default => throw new RuntimeException('TELEGRAM_CLIENT must be "http" or "fake".'),
        });

        $this->app->singleton(AiProvider::class, fn (): AiProvider => match (config('ai.provider')) {
            'deepseek' => new DeepSeekProvider,
            'fake' => new FakeAiProvider,
            default => throw new RuntimeException('AI_PROVIDER must be "deepseek" or "fake".'),
        });

        $this->app->singleton(BotMessages::class);
        $this->app->singleton(CallbackRouter::class);
        $this->app->singleton(ConfidencePolicy::class, fn (): ConfidencePolicy => ConfidencePolicy::fromConfig());

        Connection::resolverFor('pgsql', fn ($connection, $database, $prefix, $config) => new PostgresConnection($connection, $database, $prefix, $config));
    }

    /**
     * Settings that must never be loose in production, whatever the .env says (PRD §56): no debug pages (they print
     * configuration and stack traces), session cookies only over HTTPS and out of reach of scripts, generated URLs
     * (signed report links, redirects) always https.
     */
    private function hardenProduction(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        config([
            'app.debug' => false,
            'session.secure' => true,
            'session.http_only' => true,
            'session.same_site' => in_array(config('session.same_site'), ['lax', 'strict'], true) ? config('session.same_site') : 'lax',
        ]);

        URL::forceScheme('https');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Livewire component updates are separate requests: they must bind the user context too (fails closed otherwise).
        Livewire::addPersistentMiddleware([BindUserContext::class]);

        // A fake Telegram client in production would silently swallow every user-facing message.
        if ($this->app->isProduction() && config('telegram.client') !== 'http') {
            throw new RuntimeException('TELEGRAM_CLIENT must be "http" in production.');
        }

        $this->hardenProduction();

        // A fake probe in production would report a dead system as healthy.
        if ($this->app->isProduction() && config('ops.probe') !== 'real') {
            throw new RuntimeException('OPS_PROBE must be "real" in production.');
        }

        // Heartbeats: a worker loop (idle or busy) proves the worker of those queues is alive.
        Event::listen(Looping::class, function (Looping $event): void {
            foreach (explode(',', $event->queue) as $queue) {
                Cache::put(HealthChecks::workerKey(trim($queue)), Carbon::now('UTC')->getTimestamp(), 3600);
            }
        });

        // A fake PDF renderer in production would hand out placeholder files as reports.
        if ($this->app->isProduction() && config('reports.pdf.renderer') !== 'gotenberg') {
            throw new RuntimeException('PDF_RENDERER must be "gotenberg" in production.');
        }

        // A fake AI provider in production would silently record nothing for every message.
        if ($this->app->isProduction() && config('ai.provider') !== 'deepseek') {
            throw new RuntimeException('AI_PROVIDER must be "deepseek" in production.');
        }

        // Telegram delivers from a small set of addresses; the limit is generous but stops floods.
        // Button handlers register themselves on the singleton router the first time it is resolved.
        $this->app->afterResolving(CallbackRouter::class, function (CallbackRouter $router): void {
            $this->app->make(PendingAnswerHandler::class)->register($router);
            $this->app->make(CorrectionHandler::class)->register($router);
            $this->app->make(NavigationHandler::class)->register($router);
            $this->app->make(ReminderHandler::class)->register($router);
            $this->app->make(ReportReviewHandler::class)->register($router);
            $router->onReportStart(fn ($data, $update, $user, $language) => $this->app->make(ReportCommands::class)->startFromButton($data, $update, $user, $language));
        });

        RateLimiter::for('telegram-webhook', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip() ?? 'unknown'));
    }
}
