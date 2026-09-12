<?php

namespace Tests\Feature\Console\Commands;

use App\Models\FailedWebhook;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CheckFailedWebhooksTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_lists_unresolved_failures_and_exits_non_zero(): void
    {
        $failureWithEvent = FailedWebhook::factory()->create([
            'provider_event_id' => 'evt_123',
            'attempts' => 3,
            'last_failed_at' => '2026-09-12 10:30:00',
            'last_error' => 'The data.object.amount field must be at least 0.',
        ]);
        $failureWithoutEvent = FailedWebhook::factory()->create([
            'provider_event_id' => null,
            'attempts' => 1,
            'last_failed_at' => '2026-09-12 10:35:00',
            'last_error' => 'The webhook payload must be a JSON object.',
        ]);
        FailedWebhook::factory()->resolved()->create(['provider_event_id' => 'evt_resolved']);

        $this->artisan('webhooks:check-failures')
            ->expectsOutputToContain('2 unresolved payment webhook failure(s).')
            ->expectsTable(['ID', 'Provider event', 'Attempts', 'Last failure', 'Error'], [
                [$failureWithEvent->id, 'evt_123', 3, '2026-09-12 10:30:00', 'The data.object.amount field must be at least 0.'],
                [$failureWithoutEvent->id, '(none)', 1, '2026-09-12 10:35:00', 'The webhook payload must be a JSON object.'],
            ])
            ->doesntExpectOutputToContain('evt_resolved')
            ->assertFailed()
            ->run();
    }

    public function test_prints_success_and_exits_zero_when_every_failure_is_resolved(): void
    {
        FailedWebhook::factory()->resolved()->create();

        $this->artisan('webhooks:check-failures')
            ->expectsOutput('No unresolved payment webhook failures.')
            ->assertSuccessful()
            ->run();
    }
}
