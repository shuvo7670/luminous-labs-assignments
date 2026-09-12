<?php

namespace Tests\Feature\Console\Commands;

use Database\Seeders\EventSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReviewDemoTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_passes_every_check_and_rolls_back_its_changes(): void
    {
        config(['payment-provider.webhook_secret' => 'test-webhook-secret']);
        $this->seed(EventSeeder::class);

        $this->artisan('review:demo')
            ->expectsOutputToContain('All checks passed')
            ->doesntExpectOutputToContain('FAIL')
            ->assertSuccessful()
            ->run();

        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseEmpty('shipments');
        $this->assertDatabaseEmpty('failed_webhooks');
    }

    public function test_exits_non_zero_when_a_check_fails(): void
    {
        config(['payment-provider.webhook_secret' => '']);
        $this->seed(EventSeeder::class);

        $this->artisan('review:demo')
            ->expectsOutputToContain('FAIL')
            ->doesntExpectOutputToContain('All checks passed')
            ->assertFailed()
            ->run();
    }
}
