<?php

namespace App\Services;

use App\Models\FailedWebhook;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentWebhookProcessor
{
    /**
     * Process an authenticated webhook payload and resolve earlier failures of the same event.
     *
     * @return 'created'|'duplicate'|'ignored'
     *
     * @throws ValidationException
     */
    public function process(string $payload): string
    {
        $event = $this->decodeEvent($payload);

        $outcome = match ($event['type']) {
            'payment.succeeded' => $this->handlePaymentSucceeded($event),
            default => 'ignored',
        };

        FailedWebhook::query()
            ->where('provider_event_id', $event['id'])
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);

        return $outcome;
    }

    /**
     * Record a failed delivery, incrementing the attempts of an event that failed before.
     */
    public function recordFailure(string $payload, Throwable $exception): FailedWebhook
    {
        $eventId = $this->eventIdFrom($payload);

        $attributes = [
            'last_error' => $exception instanceof ValidationException
                ? implode(' ', Arr::flatten($exception->errors()))
                : $exception::class.': '.$exception->getMessage(),
            'last_failed_at' => now(),
            'resolved_at' => null,
            'payload' => $payload,
        ];

        if ($eventId === null) {
            $failedWebhook = FailedWebhook::create([...$attributes, 'attempts' => 1]);
        } else {
            $failedWebhook = FailedWebhook::firstOrCreate(['provider_event_id' => $eventId], [...$attributes, 'attempts' => 0]);
            $failedWebhook->increment('attempts', 1, $attributes);
        }

        Log::error('Payment webhook processing failed.', [
            'failed_webhook_id' => $failedWebhook->id,
            'provider_event_id' => $eventId,
            'attempts' => $failedWebhook->attempts,
            'exception' => $exception,
        ]);

        return $failedWebhook;
    }

    /**
     * Decode the payload and validate the fields shared by every event type.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function decodeEvent(string $payload): array
    {
        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw ValidationException::withMessages(['payload' => 'The webhook payload must be a JSON object.']);
        }

        Validator::make($event, [
            'id' => ['required', 'string'],
            'type' => ['required', 'string'],
        ])->validate();

        return $event;
    }

    /**
     * Create the order for a successful payment exactly once per provider payment ID.
     *
     * The unique key on (provider, provider_payment_id) is the idempotency boundary:
     * when concurrent deliveries race, firstOrCreate() returns the row that won.
     *
     * @param  array<string, mixed>  $event
     * @return 'created'|'duplicate'
     *
     * @throws ValidationException
     */
    private function handlePaymentSucceeded(array $event): string
    {
        $payment = Validator::make($event, [
            'data.object' => ['required', 'array'],
            'data.object.id' => ['required', 'string'],
            'data.object.amount' => ['required', 'integer:strict', 'min:0'],
            'data.object.currency' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'data.object.customer_email' => ['required', 'email'],
            'data.object.paid_at' => ['required', 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:sp,Y-m-d\TH:i:s.vP,Y-m-d\TH:i:s.vp'],
        ])->validate()['data']['object'];

        $order = Order::firstOrCreate([
            'provider' => config('payment-provider.name'),
            'provider_payment_id' => $payment['id'],
        ], [
            'provider_event_id' => $event['id'],
            'amount' => $payment['amount'],
            'currency' => strtoupper($payment['currency']),
            'customer_email' => $payment['customer_email'],
            'paid_at' => CarbonImmutable::parse($payment['paid_at'])->utc(),
        ]);

        return $order->wasRecentlyCreated ? 'created' : 'duplicate';
    }

    /**
     * Extract the event ID from a payload that may be malformed.
     */
    private function eventIdFrom(string $payload): ?string
    {
        $eventId = Arr::get((array) json_decode($payload, true), 'id');

        return is_string($eventId) && $eventId !== '' ? $eventId : null;
    }
}
