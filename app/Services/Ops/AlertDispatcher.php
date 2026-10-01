<?php

namespace App\Services\Ops;

use App\Models\User;
use App\Services\Telegram\BotMessages;
use App\Services\Telegram\TelegramMessenger;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Turns conditions into Telegram alerts for the operator (PRD §78, §84): one message when a condition becomes a problem,
 * a reminder every 24 hours while it lasts, and one "recovered" message when it clears. Alerts quote numbers and keys
 * only. State lives in the cache: losing it just means one repeated alert.
 */
class AlertDispatcher
{
    private const REPEAT_SECONDS = 86_400;

    public function __construct(
        private readonly UserContext $context,
        private readonly TelegramMessenger $messenger,
        private readonly BotMessages $messages,
    ) {}

    /**
     * @param  list<OpsCondition>  $conditions
     * @return array{raised: list<string>, recovered: list<string>}
     */
    public function reconcile(array $conditions): array
    {
        $raised = [];
        $recovered = [];
        $now = Carbon::now('UTC')->getTimestamp();

        foreach ($conditions as $c) {
            $key = 'ops:alert:'.$c->key;
            $since = Cache::get($key);

            if ($c->active) {
                $lastSent = is_array($since) ? (int) $since['sent'] : null;

                if ($lastSent === null || $now - $lastSent >= self::REPEAT_SECONDS) {
                    $this->send('ops.alert.'.$c->key, $c);
                    Cache::forever($key, ['since' => is_array($since) ? (int) $since['since'] : $now, 'sent' => $now]);
                    $raised[] = $c->key;
                    $this->context->runAsSystem(fn () => OpsEvents::record(OpsEvents::ALERT, ['key' => $c->key, 'state' => 'raised']));
                }
            } elseif ($since !== null) {
                $this->send('ops.recovered.'.$c->key, $c);
                Cache::forget($key);
                $recovered[] = $c->key;
                $this->context->runAsSystem(fn () => OpsEvents::record(OpsEvents::ALERT, ['key' => $c->key, 'state' => 'recovered']));
            }
        }

        return ['raised' => $raised, 'recovered' => $recovered];
    }

    private function send(string $template, OpsCondition $c): void
    {
        $admin = $this->admin();

        if ($admin === null) {
            return;
        }

        $this->messenger->trySend((int) $admin->telegram_user_id, $this->messages->get($template, $admin->default_language, [
            'value' => (string) ($c->value ?? '-'),
            'limit' => (string) ($c->limit ?? '-'),
        ]));
    }

    private function admin(): ?User
    {
        return $this->context->runAsSystem(function (): ?User {
            $id = config('ops.admin_telegram_user_id');

            $query = User::query()->whereNotNull('telegram_user_id');

            return is_numeric($id) && (int) $id > 0
                ? $query->where('telegram_user_id', (int) $id)->first()
                : $query->orderBy('id')->first();
        });
    }
}
