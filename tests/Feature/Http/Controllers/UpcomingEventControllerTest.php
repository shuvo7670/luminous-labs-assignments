<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class UpcomingEventControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string NOW = '2026-09-12 10:30:00';

    public function test_returns_only_published_events_starting_strictly_after_now(): void
    {
        $this->travelTo(self::NOW);
        $startingOneSecondFromNow = Event::factory()->create(['starts_at' => '2026-09-12 10:30:01']);
        Event::factory()->create(['starts_at' => self::NOW]);
        Event::factory()->create(['starts_at' => '2026-09-12 10:29:59']);
        Event::factory()->draft()->create(['starts_at' => '2026-09-13 10:00:00']);
        Event::factory()->cancelled()->create(['starts_at' => '2026-09-13 10:00:00']);

        $response = $this->getJson('/api/events/upcoming');

        $response->assertOk();
        $this->assertSame([$startingOneSecondFromNow->id], $response->json('data.*.id'));
    }

    public function test_orders_events_by_start_time_then_by_id(): void
    {
        $this->travelTo(self::NOW);
        $later = Event::factory()->create(['starts_at' => '2026-09-14 18:00:00']);
        $firstOfTie = Event::factory()->create(['starts_at' => '2026-09-13 18:00:00']);
        $secondOfTie = Event::factory()->create(['starts_at' => '2026-09-13 18:00:00']);

        $response = $this->getJson('/api/events/upcoming');

        $response->assertOk();
        $this->assertSame([$firstOfTie->id, $secondOfTie->id, $later->id], $response->json('data.*.id'));
    }

    public function test_returns_at_most_the_next_ten_events(): void
    {
        $this->travelTo(self::NOW);
        $events = Event::factory()
            ->count(11)
            ->sequence(fn (Sequence $sequence): array => [
                'starts_at' => now()->addHours($sequence->index + 1),
            ])
            ->create();

        $response = $this->getJson('/api/events/upcoming');

        $response->assertOk()->assertJsonCount(10, 'data');
        $this->assertSame($events->take(10)->pluck('id')->all(), $response->json('data.*.id'));
    }

    public function test_exposes_only_public_event_fields(): void
    {
        $this->travelTo(self::NOW);
        $event = Event::factory()->create([
            'name' => 'Marlow Autumn Gala',
            'description' => 'An evening of music on the river.',
            'venue' => 'Marlow Town Hall',
            'starts_at' => '2026-09-13 19:00:00',
        ]);

        $response = $this->getJson('/api/events/upcoming');

        $response->assertOk()->assertExactJson([
            'data' => [
                [
                    'id' => $event->id,
                    'name' => 'Marlow Autumn Gala',
                    'starts_at' => '2026-09-13T19:00:00+00:00',
                    'venue' => 'Marlow Town Hall',
                    'description' => 'An evening of music on the river.',
                ],
            ],
        ]);
    }
}
