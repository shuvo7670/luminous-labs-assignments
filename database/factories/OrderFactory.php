<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'payment-provider',
            'provider_payment_id' => 'pay_'.Str::random(24),
            'provider_event_id' => 'evt_'.Str::random(24),
            'amount' => fake()->numberBetween(100, 500_000),
            'currency' => fake()->currencyCode(),
            'customer_email' => fake()->safeEmail(),
            'paid_at' => now(),
        ];
    }
}
