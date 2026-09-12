<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'venue' => fake()->city().' Hall',
            'starts_at' => now()->addDays(fake()->numberBetween(1, 90))->setTime(fake()->numberBetween(9, 21), 0),
            'status' => Event::STATUS_PUBLISHED,
        ];
    }

    /**
     * Indicate that the event has already started.
     */
    public function past(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subDays(fake()->numberBetween(1, 90))->setTime(fake()->numberBetween(9, 21), 0),
        ]);
    }

    /**
     * Indicate that the event has not been published.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Event::STATUS_DRAFT,
        ]);
    }

    /**
     * Indicate that the event has been cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Event::STATUS_CANCELLED,
        ]);
    }
}
