<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\FailedWebhook;
use App\Models\Order;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PaymentWebhookControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string SECRET = 'test-webhook-secret';

    private const string NOW = '2026-09-12 10:30:00';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment-provider.webhook_secret' => self::SECRET]);
    }

    public function test_creates_an_order_for_a_payment_succeeded_event(): void
    {
        $this->travelTo(self::NOW);

        $response = $this->postSignedWebhook(self::paymentSucceededEvent(paymentOverrides: [
            'currency' => 'gbp',
            'paid_at' => '2026-09-12T11:15:00+01:00',
        ]));

        $response->assertOk()->assertExactJson(['status' => 'created']);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', [
            'provider' => 'payment-provider',
            'provider_payment_id' => 'pay_123',
            'provider_event_id' => 'evt_123',
            'amount' => 4999,
            'currency' => 'GBP',
            'customer_email' => 'customer@example.com',
            'paid_at' => '2026-09-12 10:15:00',
        ]);
        $this->assertDatabaseEmpty('failed_webhooks');
    }

    public function test_retried_delivery_of_the_same_event_returns_200_without_creating_another_order(): void
    {
        $this->postSignedWebhook(self::paymentSucceededEvent());

        $response = $this->postSignedWebhook(self::paymentSucceededEvent());

        $response->assertOk()->assertExactJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_different_event_for_an_already_recorded_payment_returns_200_without_changing_the_order(): void
    {
        $this->postSignedWebhook(self::paymentSucceededEvent('evt_first'));

        $response = $this->postSignedWebhook(self::paymentSucceededEvent('evt_second', ['amount' => 1]));

        $response->assertOk()->assertExactJson(['status' => 'duplicate']);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', [
            'provider_payment_id' => 'pay_123',
            'provider_event_id' => 'evt_first',
            'amount' => 4999,
        ]);
    }

    public function test_acknowledges_and_ignores_unknown_event_types(): void
    {
        $response = $this->postSignedWebhook([
            'id' => 'evt_123',
            'type' => 'payment.refunded',
            'data' => ['object' => ['id' => 'pay_123']],
        ]);

        $response->assertOk()->assertExactJson(['status' => 'ignored']);

        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseEmpty('failed_webhooks');
    }

    /**
     * @return array<string, array{string, string|null, string}>
     */
    public static function malformedPayloads(): array
    {
        $withPayment = fn (array $paymentOverrides): string => json_encode(
            self::paymentSucceededEvent(paymentOverrides: $paymentOverrides),
            JSON_THROW_ON_ERROR,
        );

        return [
            'amount as a numeric string' => [$withPayment(['amount' => '4999']), 'evt_123', 'data.object.amount'],
            'amount with decimals' => [$withPayment(['amount' => 49.99]), 'evt_123', 'data.object.amount'],
            'negative amount' => [$withPayment(['amount' => -1]), 'evt_123', 'data.object.amount'],
            'paid_at not ISO-8601' => [$withPayment(['paid_at' => '12/09/2026 10:15']), 'evt_123', 'data.object.paid at field must match the format'],
            'missing payment id' => [$withPayment(['id' => null]), 'evt_123', 'data.object.id'],
            'missing currency' => [$withPayment(['currency' => null]), 'evt_123', 'data.object.currency'],
            'missing event id' => ['{"type":"payment.succeeded"}', null, 'The id field is required.'],
            'body is not a JSON object' => ['not-json', null, 'The webhook payload must be a JSON object.'],
        ];
    }

    #[DataProvider('malformedPayloads')]
    public function test_records_a_malformed_payload_as_a_failure_and_returns_422(string $body, ?string $expectedEventId, string $expectedError): void
    {
        $this->travelTo(self::NOW);

        $response = $this->postSignedWebhook($body);

        $response->assertUnprocessable()->assertExactJson(['message' => 'The webhook payload is invalid.']);

        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseHas('failed_webhooks', [
            'provider_event_id' => $expectedEventId,
            'attempts' => 1,
            'last_failed_at' => self::NOW,
            'resolved_at' => null,
            'payload' => $body,
        ]);
        $this->assertStringContainsString($expectedError, FailedWebhook::sole()->last_error);
    }

    public function test_records_an_unexpected_exception_as_a_failure_logs_it_and_returns_500(): void
    {
        $this->travelTo(self::NOW);
        Order::creating(fn () => throw new RuntimeException('Database unavailable.'));
        Log::spy();

        $response = $this->postSignedWebhook(self::paymentSucceededEvent());

        $response->assertInternalServerError()->assertExactJson(['message' => 'The webhook could not be processed.']);

        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseHas('failed_webhooks', [
            'provider_event_id' => 'evt_123',
            'attempts' => 1,
            'last_error' => 'RuntimeException: Database unavailable.',
            'last_failed_at' => self::NOW,
            'resolved_at' => null,
        ]);

        Log::shouldHaveReceived('error')->once()->with(
            'Payment webhook processing failed.',
            Mockery::on(fn (array $context): bool => $context['provider_event_id'] === 'evt_123'
                && $context['exception'] instanceof RuntimeException),
        );
    }

    public function test_repeated_failure_of_the_same_event_increments_attempts_and_reopens_it(): void
    {
        $this->travelTo(self::NOW);
        FailedWebhook::factory()->resolved()->create([
            'provider_event_id' => 'evt_123',
            'attempts' => 2,
            'last_failed_at' => '2026-09-12 09:00:00',
        ]);

        $response = $this->postSignedWebhook(self::paymentSucceededEvent(paymentOverrides: ['amount' => -1]));

        $response->assertUnprocessable();

        $this->assertDatabaseCount('failed_webhooks', 1);
        $this->assertDatabaseHas('failed_webhooks', [
            'provider_event_id' => 'evt_123',
            'attempts' => 3,
            'last_failed_at' => self::NOW,
            'resolved_at' => null,
        ]);
    }

    public function test_successful_retry_marks_the_earlier_failure_of_that_event_resolved(): void
    {
        $this->travelTo(self::NOW);
        $failureOfThisEvent = FailedWebhook::factory()->create(['provider_event_id' => 'evt_123']);
        $failureOfOtherEvent = FailedWebhook::factory()->create(['provider_event_id' => 'evt_other']);

        $response = $this->postSignedWebhook(self::paymentSucceededEvent());

        $response->assertOk()->assertExactJson(['status' => 'created']);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('failed_webhooks', ['id' => $failureOfThisEvent->id, 'resolved_at' => self::NOW]);
        $this->assertDatabaseHas('failed_webhooks', ['id' => $failureOfOtherEvent->id, 'resolved_at' => null]);
    }

    /**
     * Build a valid payment.succeeded event.
     *
     * @param  array<string, mixed>  $paymentOverrides
     * @return array<string, mixed>
     */
    private static function paymentSucceededEvent(string $eventId = 'evt_123', array $paymentOverrides = []): array
    {
        return [
            'id' => $eventId,
            'type' => 'payment.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pay_123',
                    'amount' => 4999,
                    'currency' => 'GBP',
                    'customer_email' => 'customer@example.com',
                    'paid_at' => '2026-09-12T10:15:00Z',
                    ...$paymentOverrides,
                ],
            ],
        ];
    }

    /**
     * Send a webhook signed with the test secret at the current time.
     *
     * @param  array<string, mixed>|string  $event
     */
    private function postSignedWebhook(array|string $event): TestResponse
    {
        $body = is_string($event) ? $event : json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = now()->getTimestamp();

        return $this->call('POST', '/webhooks/payment-provider', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYMENT_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET),
        ], content: $body);
    }
}
