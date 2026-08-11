<?php

namespace Database\Factories;

use App\Enums\CallStatus;
use App\Models\Call;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    protected $model = Call::class;

    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'direction' => 'outbound',
            'status' => CallStatus::Connected->value,
            'started_at' => now(),
            'duration_seconds' => 0,
            'dial_source' => 'manual',
        ];
    }

    public function connected(int $seconds = 120): static
    {
        return $this->state(fn () => [
            'status' => CallStatus::Connected->value,
            'duration_seconds' => $seconds,
        ]);
    }
}
