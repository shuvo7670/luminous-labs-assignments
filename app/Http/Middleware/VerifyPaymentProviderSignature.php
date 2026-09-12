<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyPaymentProviderSignature
{
    /**
     * Reject requests whose Payment-Signature header does not authenticate the exact raw body.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->hasValidSignature($request)) {
            return response()->json(['message' => 'Invalid signature.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    /**
     * Determine if the request was signed with the shared secret within the allowed tolerance.
     */
    private function hasValidSignature(Request $request): bool
    {
        $secret = config('payment-provider.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            Log::error('Payment provider webhook rejected because PAYMENT_PROVIDER_WEBHOOK_SECRET is not configured.');

            return false;
        }

        $signature = $this->parseSignatureHeader((string) $request->header('Payment-Signature', ''));

        if ($signature === null) {
            return false;
        }

        $secondsSinceSigned = abs(now()->getTimestamp() - (int) $signature['timestamp']);

        if ($secondsSinceSigned > config('payment-provider.signature_tolerance')) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $signature['timestamp'].'.'.$request->getContent(), $secret);

        return hash_equals($expectedSignature, $signature['v1']);
    }

    /**
     * Parse a "t=<unix timestamp>,v1=<hex signature>" header.
     *
     * @return array{timestamp: string, v1: string}|null
     */
    private function parseSignatureHeader(string $header): ?array
    {
        $elements = [];

        foreach (explode(',', $header) as $element) {
            [$key, $value] = array_pad(explode('=', trim($element), 2), 2, '');

            $elements[$key] = $value;
        }

        if (! isset($elements['t'], $elements['v1']) || ! ctype_digit($elements['t'])) {
            return null;
        }

        return ['timestamp' => $elements['t'], 'v1' => $elements['v1']];
    }
}
