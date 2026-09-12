<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class PaymentWebhookController extends Controller
{
    /**
     * Acknowledge a payment provider webhook whose signature has been verified.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'received']);
    }
}
