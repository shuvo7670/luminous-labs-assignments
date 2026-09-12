<?php

use App\Http\Controllers\PaymentWebhookController;
use App\Http\Middleware\VerifyPaymentProviderSignature;
use Illuminate\Support\Facades\Route;

Route::post('/payment-provider', PaymentWebhookController::class)
    ->middleware(VerifyPaymentProviderSignature::class)
    ->name('payment-provider');
