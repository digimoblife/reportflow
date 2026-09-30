<?php

namespace Database\Factories;

use App\Enums\InboundMessageStatus;
use App\Enums\MessageSource;
use App\Models\InboundMessage;
use Database\Factories\Concerns\ResolvesContextUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InboundMessage>
 */
class InboundMessageFactory extends Factory
{
    use ResolvesContextUser;

    public function definition(): array
    {
        $chatId = fake()->numberBetween(100_000_000, 999_999_999);
        $messageId = fake()->unique()->numberBetween(1, 9_999_999);

        return [
            'user_id' => $this->contextUser(),
            'source' => MessageSource::Telegram,
            'idempotency_key' => "telegram:{$chatId}:{$messageId}",
            'telegram_chat_id' => $chatId,
            'telegram_message_id' => $messageId,
            'reply_message_id' => null,
            'text' => fake()->sentence(),
            'attachments' => [],
            'received_at' => now(),
            'edited_at' => null,
            'status' => InboundMessageStatus::Received,
            'error' => null,
            'reprocess_count' => 0,
        ];
    }

    public function fromDashboard(): static
    {
        return $this->state(fn () => [
            'source' => MessageSource::Dashboard,
            'idempotency_key' => 'dashboard:'.Str::uuid(),
            'telegram_chat_id' => null,
            'telegram_message_id' => null,
        ]);
    }

    public function status(InboundMessageStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
