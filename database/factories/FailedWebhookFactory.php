<?php

namespace Database\Factories;

use App\Models\FailedWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FailedWebhook>
 */
class FailedWebhookFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider_event_id' => 'evt_'.Str::random(24),
            'attempts' => 1,
            'last_error' => fake()->sentence(),
            'last_failed_at' => now(),
            'resolved_at' => null,
            'payload' => fn (array $attributes): string => json_encode([
                'id' => $attributes['provider_event_id'],
                'type' => 'payment.succeeded',
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Indicate that a later delivery of the webhook succeeded.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'resolved_at' => now(),
        ]);
    }
}
