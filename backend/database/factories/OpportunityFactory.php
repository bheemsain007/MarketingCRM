<?php

namespace Database\Factories;

use App\Enums\OpportunityStatus;
use App\Models\Lead;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    protected $model = Opportunity::class;

    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'title' => $this->faker->words(3, true),
            'status' => OpportunityStatus::Open->value,
            'value' => 0,
            'currency' => 'INR',
        ];
    }

    public function won(): static
    {
        return $this->state(fn () => [
            'status' => OpportunityStatus::Won->value,
            'closed_at' => now(),
        ]);
    }

    public function lost(): static
    {
        return $this->state(fn () => [
            'status' => OpportunityStatus::Lost->value,
            'lost_reason' => 'price',
            'closed_at' => now(),
        ]);
    }
}
