<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    protected $model = FollowUp::class;

    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'channel' => Channel::Call->value,
            'scheduled_at' => now()->addDay(),
            'status' => FollowUpStatus::Open->value,
            'subject' => $this->faker->sentence(4),
        ];
    }

    public function missed(): static
    {
        return $this->state(fn () => [
            'status' => FollowUpStatus::Missed->value,
            'scheduled_at' => now()->subDay(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => FollowUpStatus::Completed->value,
            'completed_at' => now(),
        ]);
    }
}
