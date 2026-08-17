<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Models\Template;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    protected $model = Template::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->words(3, true);

        return [
            'tenant_id' => config('crm.default_tenant_id'),
            'name' => ucwords($name),
            'code' => strtoupper(str_replace(' ', '_', $name)),
            'channel' => Channel::Email->value,
            'subject' => 'Hello {{ lead_name }}',
            // Carries real placeholders so any test that renders one exercises
            // substitution rather than a static string.
            'body' => 'Hi {{ lead_name }}, {{ organisation }} has an offer for you.',
            'approval_status' => 'approved',
            'is_active' => true,
        ];
    }

    /** Subject travels with the channel - only email has one. */
    public function channel(Channel $channel): static
    {
        return $this->state(fn () => [
            'channel' => $channel->value,
            'subject' => $channel === Channel::Email ? 'Hello {{ lead_name }}' : null,
        ]);
    }

    /** Retired: still present and still referenced, just out of circulation. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
