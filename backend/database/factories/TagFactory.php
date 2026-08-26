<?php

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // unique(): the slug is what (tenant_id, slug) is indexed on, so two
        // factory tags that happened to share a name would fail the insert
        // rather than the assertion, and the failure would read as unrelated.
        $name = ucwords($this->faker->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'color' => '#6B7280',
            'is_system' => false,
        ];
    }

    /** A tag the Interest Engine owns: visible everywhere, editable nowhere. */
    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }
}
