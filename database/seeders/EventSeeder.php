<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    /**
     * Seed a mix of events that the upcoming events endpoint should and should not return.
     */
    public function run(): void
    {
        Event::factory()->count(12)->create();
        Event::factory()->count(3)->past()->create();
        Event::factory()->count(2)->draft()->create();
        Event::factory()->count(2)->cancelled()->create();
    }
}
