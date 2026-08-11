<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Enums\LeadTemperature;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        // Indian mobile numbers in E.164 - matches the real data shape so
        // normalisation and duplicate-detection tests are meaningful.
        $phone = '+91'.$this->faker->numberBetween(6000000000, 9999999999);

        return [
            'name' => $this->faker->name(),
            'company' => $this->faker->optional(0.4)->company(),
            'phone_e164' => $phone,
            'phone_raw' => substr($phone, 3),
            'email' => $this->faker->optional(0.7)->safeEmail(),
            'city' => $this->faker->city(),
            'state' => $this->faker->state(),
            'country' => 'India',
            'timezone' => 'Asia/Kolkata',
            'status' => LeadStatus::New->value,
            'temperature' => LeadTemperature::Cold->value,
            'score' => 0,
            'priority' => 0,
            'is_suppressed' => false,
        ];
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn () => ['status' => $status->value]);
    }

    public function hot(): static
    {
        return $this->state(fn () => [
            'status' => LeadStatus::Interested->value,
            'temperature' => LeadTemperature::Hot->value,
            'score' => 75,
            'last_engagement_at' => now()->subDays(2),
        ]);
    }

    public function warm(): static
    {
        return $this->state(fn () => [
            'temperature' => LeadTemperature::Warm->value,
            'score' => 50,
            'last_engagement_at' => now()->subDays(15),
        ]);
    }

    /**
     * Marks the denormalised flag only. Tests that exercise the DNC gate must
     * also create a DncEntry - the flag alone is not suppression (BR-DNC-01).
     */
    public function suppressed(): static
    {
        return $this->state(fn () => ['is_suppressed' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['deleted_at' => now()]);
    }

    public function withoutEmail(): static
    {
        return $this->state(fn () => ['email' => null]);
    }

    public function assignedTo(int $userId): static
    {
        return $this->state(fn () => [
            'assigned_to' => $userId,
            'assigned_at' => now(),
        ]);
    }
}
