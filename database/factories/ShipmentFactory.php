<?php

namespace Database\Factories;

use App\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => 'NG-'.fake()->unique()->numerify('######'),
            'regional_office' => 'northgate-iso',
            'source_batch' => 'batch-iso-'.fake()->numerify('###'),
            'shipment_date' => CarbonImmutable::createFromFormat('!Y-m-d', fake()->date(), 'UTC'),
            'shipment_date_raw' => fn (array $attributes): string => $attributes['shipment_date']->format('Y-m-d'),
        ];
    }
}
