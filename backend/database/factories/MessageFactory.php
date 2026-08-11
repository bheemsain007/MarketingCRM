<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'channel' => Channel::Email->value,
            'direction' => 'outbound',
            'recipient' => $this->faker->safeEmail(),
            'subject' => $this->faker->sentence(4),
            'body' => $this->faker->paragraph(),
            'status' => 'queued',
            // Unique in the database, so every factory row needs its own.
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => 'sent',
            'provider' => 'mailercloud',
            'provider_message_id' => 'mc-'.Str::random(8),
            'sent_at' => now(),
        ]);
    }

    public function skipped(string $reason = 'suppressed'): static
    {
        return $this->state(fn () => ['status' => 'skipped', 'skip_reason' => $reason]);
    }
}
