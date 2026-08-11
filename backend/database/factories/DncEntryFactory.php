<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Enums\DncReason;
use App\Models\DncEntry;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DncEntry>
 */
class DncEntryFactory extends Factory
{
    protected $model = DncEntry::class;

    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'reason' => DncReason::NotInterested->value,
            'channel' => null,   // null = blocks every channel
            'source' => 'manual',
            'active' => true,
        ];
    }

    public function reason(DncReason $reason): static
    {
        return $this->state(fn () => ['reason' => $reason->value]);
    }

    /** A channel-specific opt-out, e.g. STOP on SMS. */
    public function forChannel(Channel $channel): static
    {
        return $this->state(fn () => [
            'reason' => DncReason::OptedOut->value,
            'channel' => $channel->value,
        ]);
    }

    public function wrongNumber(): static
    {
        return $this->state(fn () => [
            'reason' => DncReason::WrongNumber->value,
            'channel' => null,
            'source' => 'call_outcome',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'active' => false,
            'removed_at' => now(),
            'removal_reason' => 'Removed for test',
        ]);
    }
}
