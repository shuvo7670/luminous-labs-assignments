<?php

namespace Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class VerifyPaymentProviderSignatureTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const string SECRET = 'test-webhook-secret';

    private const int NOW = 1_790_000_000;

    /**
     * Deliberately non-canonical JSON, so verifying a re-encoded body would fail.
     */
    private const string BODY = '{ "id": "evt_123",  "type": "payment.succeeded" }';

    protected function setUp(): void
    {
        parent::setUp();

        config(['payment-provider.webhook_secret' => self::SECRET]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function acceptedSignatureTimestamps(): array
    {
        return [
            'signed now' => [self::NOW],
            'signed exactly 300 seconds ago' => [self::NOW - 300],
        ];
    }

    #[DataProvider('acceptedSignatureTimestamps')]
    public function test_accepts_request_signed_over_the_exact_raw_body(int $timestamp): void
    {
        $this->travelTo(Carbon::createFromTimestamp(self::NOW));

        $response = $this->postWebhook(self::signatureHeader($timestamp, self::BODY));

        $response->assertOk()->assertExactJson(['status' => 'received']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidSignatureHeaders(): array
    {
        return [
            'signed with a different secret' => [self::signatureHeader(self::NOW, self::BODY, 'wrong-secret')],
            'signed over a re-encoded body' => [self::signatureHeader(self::NOW, '{"id":"evt_123","type":"payment.succeeded"}')],
            'signature value tampered' => ['t='.self::NOW.',v1='.str_repeat('0', 64)],
            'v1 element missing' => ['t='.self::NOW],
            'timestamp not numeric' => [self::signatureHeader('now', self::BODY)],
            'unparseable header' => ['not-a-signature'],
        ];
    }

    #[DataProvider('invalidSignatureHeaders')]
    public function test_returns_401_and_stores_nothing_when_signature_is_invalid(string $signatureHeader): void
    {
        $this->travelTo(Carbon::createFromTimestamp(self::NOW));

        $response = $this->postWebhook($signatureHeader);

        $this->assertRejectedWithoutStoringAnything($response);
    }

    public function test_returns_401_and_stores_nothing_when_signature_header_is_missing(): void
    {
        $response = $this->postWebhook(null);

        $this->assertRejectedWithoutStoringAnything($response);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function staleSignatureTimestamps(): array
    {
        return [
            'signed 301 seconds ago' => [self::NOW - 301],
            'signed 301 seconds in the future' => [self::NOW + 301],
        ];
    }

    #[DataProvider('staleSignatureTimestamps')]
    public function test_returns_401_and_stores_nothing_when_signature_is_outside_the_tolerance(int $timestamp): void
    {
        $this->travelTo(Carbon::createFromTimestamp(self::NOW));

        $response = $this->postWebhook(self::signatureHeader($timestamp, self::BODY));

        $this->assertRejectedWithoutStoringAnything($response);
    }

    #[TestWith([''])]
    #[TestWith([null])]
    public function test_returns_401_when_webhook_secret_is_not_configured(?string $configuredSecret): void
    {
        $this->travelTo(Carbon::createFromTimestamp(self::NOW));
        config(['payment-provider.webhook_secret' => $configuredSecret]);

        $response = $this->postWebhook(self::signatureHeader(self::NOW, self::BODY, ''));

        $this->assertRejectedWithoutStoringAnything($response);
    }

    /**
     * Sign a body the way the payment provider does.
     */
    private static function signatureHeader(int|string $timestamp, string $body, string $secret = self::SECRET): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    private function postWebhook(?string $signatureHeader): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signatureHeader !== null) {
            $server['HTTP_PAYMENT_SIGNATURE'] = $signatureHeader;
        }

        return $this->call('POST', '/webhooks/payment-provider', server: $server, content: self::BODY);
    }

    private function assertRejectedWithoutStoringAnything(TestResponse $response): void
    {
        $response->assertUnauthorized()->assertExactJson(['message' => 'Invalid signature.']);

        $this->assertDatabaseEmpty('orders');
        $this->assertDatabaseEmpty('failed_webhooks');
    }
}
