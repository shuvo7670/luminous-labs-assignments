<?php

namespace App\Http\Controllers;

use App\Services\PaymentWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PaymentWebhookController extends Controller
{
    /**
     * Process a payment provider webhook whose signature has been verified.
     *
     * Failures return a non-2xx status so the provider retries the delivery.
     */
    public function __invoke(Request $request, PaymentWebhookProcessor $processor): JsonResponse
    {
        $payload = $request->getContent();

        try {
            $outcome = $processor->process($payload);
        } catch (Throwable $exception) {
            $processor->recordFailure($payload, $exception);

            return $exception instanceof ValidationException
                ? response()->json(['message' => 'The webhook payload is invalid.'], Response::HTTP_UNPROCESSABLE_ENTITY)
                : response()->json(['message' => 'The webhook could not be processed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['status' => $outcome]);
    }
}
