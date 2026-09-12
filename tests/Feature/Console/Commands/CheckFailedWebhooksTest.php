<?php

namespace Tests\Feature\Console\Commands;

use App\Models\FailedWebhook;
use App\Notifications\UnresolvedWebhookFailures;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckFailedWebhooksTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string ALERT_EMAIL = 'operations@fenwick.example';

    public function test_lists_unresolved_failures_emails_operations_and_exits_non_zero(): void
    {
        Notification::fake();
        config(['payment-provider.alert_email' => self::ALERT_EMAIL]);
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
            ->expectsOutputToContain('Alert emailed to '.self::ALERT_EMAIL.'.')
            ->doesntExpectOutputToContain('evt_resolved')
            ->assertFailed()
            ->run();

        Notification::assertSentOnDemand(
            UnresolvedWebhookFailures::class,
            fn (UnresolvedWebhookFailures $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === self::ALERT_EMAIL
                && $notification->unresolvedCount === 2
                && $notification->oldestFailedAt->toDateTimeString() === '2026-09-12 10:30:00'
                && $notification->latestError === 'The webhook payload must be a JSON object.',
        );
    }

    public function test_emails_at_most_once_per_alert_interval_while_failures_remain_unresolved(): void
    {
        Notification::fake();
        config(['payment-provider.alert_email' => self::ALERT_EMAIL]);
        $this->travelTo('2026-09-12 10:00:00');
        FailedWebhook::factory()->create();
        $this->artisan('webhooks:check-failures')->assertFailed()->run();
        $this->travel(5)->minutes();
        $this->artisan('webhooks:check-failures')
            ->expectsOutputToContain('An alert email was already sent within the alert interval.')
            ->assertFailed()
            ->run();
        $this->travel(56)->minutes();

        $this->artisan('webhooks:check-failures')->assertFailed()->run();

        Notification::assertSentOnDemandTimes(UnresolvedWebhookFailures::class, 2);
    }

    public function test_warns_and_still_exits_non_zero_when_no_alert_email_is_configured(): void
    {
        Notification::fake();
        config(['payment-provider.alert_email' => null]);
        FailedWebhook::factory()->create();

        $this->artisan('webhooks:check-failures')
            ->expectsOutputToContain('No alert email is configured (PAYMENT_WEBHOOK_ALERT_EMAIL), so nobody was emailed.')
            ->assertFailed()
            ->run();

        Notification::assertNothingSent();
    }

    public function test_prints_success_and_exits_zero_when_every_failure_is_resolved(): void
    {
        Notification::fake();
        config(['payment-provider.alert_email' => self::ALERT_EMAIL]);
        FailedWebhook::factory()->resolved()->create();

        $this->artisan('webhooks:check-failures')
            ->expectsOutput('No unresolved payment webhook failures.')
            ->assertSuccessful()
            ->run();

        Notification::assertNothingSent();
    }
}
